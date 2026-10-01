<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Menu\Models\OnlineMenu;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Order\Delivery\DeliveryReadModel;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Order\Support\OrderFeedbackAccess;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

/** Sends only real, customer-meaningful provider milestones after commit. */
final class SendDeliveryWhatsAppNotification
{
    private const CUSTOMER_MILESTONES = [
        DeliveryStatus::RiderAssigned,
        DeliveryStatus::ArrivedAtPickup,
        DeliveryStatus::PickedUp,
        DeliveryStatus::InTransit,
        DeliveryStatus::ArrivedAtCustomer,
        DeliveryStatus::Delivered,
    ];

    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
        private readonly TenantContext $tenantContext,
        private readonly SettingServiceInterface $settings,
    ) {}

    public function handle(DeliveryStatusChanged $event): void
    {
        $previousTenantId = $this->tenantContext->id();
        $this->tenantContext->setId($event->tenantId);
        $this->settings->refreshSettingBinding();
        try {
            $this->send($event);
        } catch (\Throwable $exception) {
            // A provider-template or queue failure is an optional side effect.
            // It must never roll back or misreport a persisted delivery state.
            report($exception);
            if (app()->runningUnitTests()) {
                throw $exception;
            }
        } finally {
            $this->tenantContext->setId($previousTenantId);
            $this->settings->refreshSettingBinding();
        }
    }

    private function whatsAppEnabled(int $tenantId): bool
    {
        $configured = setting('whatsapp_enabled');
        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->exists();
    }

    private function send(DeliveryStatusChanged $event): void
    {
        if (! in_array($event->to, self::CUSTOMER_MILESTONES, true) || ! $this->eventEnabled($event->to)) {
            return;
        }
        $tenant = Tenant::query()->withoutGlobalScopes()->find($event->tenantId);
        if (! $tenant || ! $this->entitlements->has($tenant, 'delivery')) {
            return;
        }

        $delivery = OrderDelivery::query()->withoutGlobalScopes()->with(['order.customer', 'order.branch'])
            ->where('tenant_id', $event->tenantId)->whereKey($event->deliveryId)->first();
        $order = $delivery?->order;
        if (! $order || (int) $order->id !== $event->orderId
            || (int) $order->branch?->tenant_id !== $event->tenantId
            || $delivery->status !== $event->to
            || ! $this->whatsAppEnabled($event->tenantId)
            || ! filter_var(setting('whatsapp_delivery_alerts_enabled', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $configured = collect(setting('whatsapp_templates') ?: [])
            ->filter(fn ($item) => is_array($item) && ($item['event'] ?? null) === 'delivery_update');
        // An explicitly disabled event must not silently fall back to the
        // built-in template and send a message the restaurant turned off.
        if ($configured->isNotEmpty() && ! $configured->contains(fn (array $item) => ($item['is_active'] ?? true))) {
            return;
        }
        $template = $configured->first(fn (array $item) => ($item['is_active'] ?? true));
        $template ??= collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))
            ->first(fn (array $item) => ($item['event'] ?? null) === 'delivery_update' && ($item['is_active'] ?? true));
        $templateId = $template['template_id'] ?? $template['id'] ?? null;
        if (! $templateId) {
            return;
        }

        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()->with('conversation')
            ->where('tenant_id', $event->tenantId)->where('order_id', $order->id)->first();
        $recipient = $session?->conversation?->customer_phone;
        if (! $recipient && $order->customer?->phone_verified_at) {
            $recipient = $order->customer->phone;
        }
        $recipient = preg_replace('/\s+/', '', (string) $recipient);
        if ($recipient === '') {
            return;
        }

        // uEngage can enrich an ALLOTTED callback with the drop OTP after the
        // first rider notification. Preserve normal status idempotency while
        // allowing exactly one follow-up message containing that real OTP.
        $deliveryOtpForDedupe = $delivery->delivery_otp ?: DeliveryReadModel::riderIdentity($delivery->rider_name)[1];
        $dedupe = "whatsapp:delivery-status:{$event->tenantId}:{$event->deliveryId}:{$event->to->value}"
            .(filled($deliveryOtpForDedupe) ? ':otp:'.hash('sha256', (string) $deliveryOtpForDedupe) : '');
        if (! Cache::add($dedupe, true, now()->addDays(30))) {
            return;
        }
        try {
            [$riderName, $legacyDeliveryOtp] = DeliveryReadModel::riderIdentity($delivery->rider_name);
            $deliveryOtp = $delivery->delivery_otp ?: $legacyDeliveryOtp;
            $riderPhone = DeliveryReadModel::riderPhone($delivery->rider_phone);
            SendWhatsAppMessageJob::dispatch($recipient, $templateId, [
                'customer_name' => $order->customer?->name ?: $session?->conversation?->customer_name ?: 'Customer',
                'order_id' => $order->reference_no,
                // Existing approved NexMsg templates expose only one status
                // body slot. Keep that contract stable while making the
                // message useful: assignment updates now carry the rider,
                // contact number and delivery OTP inside that slot. The
                // dedicated aliases remain available for richer templates.
                'delivery_status' => $this->customerStatus(
                    $event->to,
                    $riderName,
                    $riderPhone,
                    $deliveryOtp,
                ),
                'rider_name' => $riderName ?: 'Rider assignment pending',
                'rider_phone' => $riderPhone ?: 'Available after assignment',
                'delivery_otp' => $deliveryOtp ?: 'Available when provided',
                'tracking_link' => $this->trackingLink($delivery),
            ], [
                'tenant_id' => $event->tenantId,
                'branch_id' => $order->branch_id,
                'audience' => 'delivery_tracking',
                'delivery_id' => $delivery->id,
                'delivery_status' => $event->to->value,
                'campaign_id' => "delivery:{$delivery->id}:{$event->to->value}",
            ]);

            if ($event->to === DeliveryStatus::Delivered) {
                $feedbackTemplate = $this->templateForEvent('feedback_request');
                if ($feedbackTemplate) {
                    SendWhatsAppMessageJob::dispatch($recipient, $feedbackTemplate, [
                        'customer_name' => $order->customer?->name ?: $session?->conversation?->customer_name ?: 'Customer',
                        'order_id' => $order->reference_no,
                        'feedback_link' => $this->feedbackLink($delivery),
                    ], [
                        'tenant_id' => $event->tenantId,
                        'branch_id' => $order->branch_id,
                        'audience' => 'feedback_request',
                        'delivery_id' => $delivery->id,
                        'campaign_id' => "feedback:delivery:{$delivery->id}",
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            Cache::forget($dedupe);
            throw $exception;
        }
    }

    private function templateForEvent(string $event): ?string
    {
        $configured = collect(setting('whatsapp_templates') ?: [])
            ->filter(fn ($item) => is_array($item) && ($item['event'] ?? null) === $event);
        if ($configured->isNotEmpty() && ! $configured->contains(fn (array $item) => ($item['is_active'] ?? true))) {
            return null;
        }
        $template = $configured->first(fn (array $item) => ($item['is_active'] ?? true));
        $template ??= collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))
            ->first(fn (array $item) => ($item['event'] ?? null) === $event && ($item['is_active'] ?? true));

        return $template['template_id'] ?? $template['id'] ?? null;
    }

    private function feedbackLink(OrderDelivery $delivery): string
    {
        $order = $delivery->order;
        $base = rtrim((string) (setting('frontend_url') ?: config('app.url')), '/');

        return $base.'/feedback/orders/'.rawurlencode((string) $order->reference_no)
            .'?source=whatsapp&token='.rawurlencode(OrderFeedbackAccess::token($order));
    }

    private function eventEnabled(DeliveryStatus $status): bool
    {
        $setting = match ($status) {
            DeliveryStatus::RiderAssigned, DeliveryStatus::ArrivedAtPickup => 'customer_delivery_assigned_notification_enabled',
            DeliveryStatus::PickedUp => 'customer_delivery_picked_up_notification_enabled',
            DeliveryStatus::InTransit, DeliveryStatus::ArrivedAtCustomer => 'customer_delivery_on_way_notification_enabled',
            DeliveryStatus::Delivered => 'customer_delivery_delivered_notification_enabled',
            default => null,
        };
        if ($setting === null) {
            return true;
        }
        $value = setting($setting);

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function label(DeliveryStatus $status): string
    {
        return match ($status) {
            DeliveryStatus::RiderAssigned => 'Rider Assigned',
            DeliveryStatus::ArrivedAtPickup => 'Rider Arrived at Restaurant',
            DeliveryStatus::PickedUp => 'Picked Up',
            DeliveryStatus::InTransit => 'On the Way',
            DeliveryStatus::ArrivedAtCustomer => 'Rider Arrived at Your Location',
            DeliveryStatus::Delivered => 'Delivered',
            DeliveryStatus::Cancelled => 'Delivery Cancelled',
            default => str($status->value)->replace('_', ' ')->title()->toString(),
        };
    }

    private function customerStatus(
        DeliveryStatus $status,
        ?string $riderName,
        ?string $riderPhone,
        ?string $deliveryOtp,
    ): string {
        $details = [$this->label($status)];

        if (in_array($status, [
            DeliveryStatus::RiderAssigned,
            DeliveryStatus::ArrivedAtPickup,
            DeliveryStatus::PickedUp,
            DeliveryStatus::InTransit,
            DeliveryStatus::ArrivedAtCustomer,
        ], true)) {
            if (filled($riderName)) {
                $details[] = 'Rider: '.str($riderName)->squish();
            }
            if (filled($riderPhone)) {
                $details[] = 'Call: '.str($riderPhone)->squish();
            }
            if (filled($deliveryOtp)) {
                $details[] = 'OTP: '.str($deliveryOtp)->squish();
            }
        }

        // NexMsg rejects template variables containing newlines or tabs.
        return str(implode(' · ', $details))->squish()->limit(500)->toString();
    }

    private function trackingLink(OrderDelivery $delivery): string
    {
        // Keep customers on the tenant-owned page for the full lifecycle. It
        // exposes provider tracking and rider data only after assignment and
        // works with Meta's approved fixed-host dynamic URL button.
        $order = $delivery->order;
        $slug = OnlineMenu::query()->withoutGlobalScopes()->where('branch_id', $order->branch_id)
            ->whereNotNull('slug')->value('slug');
        $base = rtrim((string) (setting('frontend_url') ?: config('app.url')), '/');
        if (! $slug) {
            return $base;
        }

        $token = app(CustomerTrackingToken::class)->issue((int) $delivery->tenant_id, (string) $order->reference_no);

        return $base.'/online-menu/'.rawurlencode((string) $slug).'/orders/'.rawurlencode((string) $order->reference_no)
            .'?tracking_token='.rawurlencode($token);
    }
}
