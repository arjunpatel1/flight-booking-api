<?php

namespace Modules\Order\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Jobs\SendStaffDeliveryActionAlert;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Support\TenantStaffRecipients;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\User\Models\User;

final class NotifyTenantAdminsOfDeliveryStatus implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly TenantContext $tenantContext,
        private readonly SettingServiceInterface $settings,
    ) {}

    public function handle(DeliveryStatusChanged $event): void
    {
        $previousTenantId = $this->tenantContext->id();
        $this->tenantContext->setId($event->tenantId);
        $this->settings->refreshSettingBinding();
        try {
            $this->notify($event);
        } finally {
            $this->tenantContext->setId($previousTenantId);
            $this->settings->refreshSettingBinding();
        }
    }

    private function notify(DeliveryStatusChanged $event): void
    {
        $notification = match ($event->to) {
            DeliveryStatus::RiderSearching => ['setting' => 'admin_delivery_created_notification_enabled', 'title' => 'Delivery created', 'type' => 'delivery_created', 'severity' => 'info', 'icon' => 'tabler-truck-delivery', 'color' => 'primary'],
            DeliveryStatus::Cancelled => ['setting' => 'admin_delivery_cancelled_notification_enabled', 'title' => 'Delivery cancelled', 'type' => 'delivery_cancelled', 'severity' => 'warning', 'icon' => 'tabler-truck-off', 'color' => 'warning'],
            DeliveryStatus::Investigation, DeliveryStatus::ManualReviewRequired => ['setting' => 'admin_delivery_failure_notification_enabled', 'title' => 'Delivery action required', 'type' => 'delivery_provider_failure', 'severity' => 'error', 'icon' => 'tabler-alert-triangle', 'color' => 'error'],
            default => null,
        };
        if (! $notification || ! $this->enabledByDefault('notifications_enabled') || ! $this->enabledByDefault($notification['setting'])) {
            return;
        }

        try {
            $delivery = OrderDelivery::query()->withoutGlobalScopes()->with(['order' => fn ($query) => $query->withoutGlobalScopes()])
                ->where('tenant_id', $event->tenantId)->whereKey($event->deliveryId)->where('order_id', $event->orderId)->first();
            $order = $delivery?->order;
            if (! $delivery || ! $order) {
                return;
            }
            $message = match ($event->to) {
                DeliveryStatus::Cancelled => "Delivery for order #{$order->order_number} was cancelled".($delivery->external_delivery_id ? " (task {$delivery->external_delivery_id})" : '').'. '.($delivery->failure_reason ?: 'Review the order before arranging another delivery.'),
                DeliveryStatus::Investigation, DeliveryStatus::ManualReviewRequired => "Delivery for order #{$order->order_number} needs attention. ".($delivery->failure_reason ?: 'Review the provider response before retrying or cancelling.'),
                default => "Delivery for order #{$order->order_number} was accepted by the delivery network and is searching for a driver.",
            };

            $actionUrl = "/admin/orders/{$order->id}/show";
            if ($this->enabledByDefault('notifications_in_app_enabled')) {
                TenantStaffRecipients::withAnyPermission((int) $event->tenantId, ['admin.orders.index', 'admin.orders.show'])
                    ->each(function (User $user) use ($actionUrl, $delivery, $event, $message, $notification, $order): void {
                        if (Notification::query()->where('target_user_id', $user->id)
                            ->where('type', $notification['type'])->where('action_url', $actionUrl)
                            ->where('payload->delivery_id', $delivery->id)
                            ->where('payload->delivery_status', $event->to->value)->exists()) {
                            return;
                        }
                        $this->notifications->create([
                            'title' => $notification['title'], 'message' => $message, 'type' => $notification['type'],
                            'severity' => $notification['severity'], 'icon' => $notification['icon'], 'color' => $notification['color'],
                            'action_url' => $actionUrl,
                            'payload' => ['order_id' => $order->id, 'reference_no' => $order->reference_no, 'order_number' => $order->order_number,
                                'delivery_id' => $delivery->id, 'external_delivery_id' => $delivery->external_delivery_id,
                                'delivery_status' => $event->to->value, 'reason' => $delivery->failure_reason],
                        ], $user);
                    });
            }

            if ($event->to === DeliveryStatus::Cancelled) {
                SendStaffDeliveryActionAlert::dispatch(
                    (int) $order->id,
                    (int) $event->tenantId,
                    'delivery-cancelled-'.$delivery->id,
                    $delivery->failure_reason ?: 'The delivery partner cancelled the delivery. Review the order and arrange the next action.',
                    'staff_delivery_cancelled',
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to notify tenant administrators about a delivery status.', [
                'tenant_id' => $event->tenantId, 'delivery_id' => $event->deliveryId, 'order_id' => $event->orderId,
                'status' => $event->to->value, 'exception' => $exception,
            ]);
        }
    }

    private function enabledByDefault(string $key): bool
    {
        $value = setting($key);

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
