<?php

namespace Modules\Order\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Order\Models\Order;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;

/** Send delivery failures to the tenant's configured admin WhatsApp numbers. */
final class SendStaffDeliveryActionAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
        public readonly int $tenantId,
        public readonly string $alertKey,
        public readonly string $summary,
        public readonly string $templateEvent = 'staff_delivery_not_created',
    ) {
        $this->onQueue('whatsapp')->afterCommit();
    }

    public function handle(TenantContext $context, SettingServiceInterface $settings): void
    {
        $previous = $context->id();
        $context->setId($this->tenantId);
        $settings->refreshSettingBinding();

        try {
            if (! filter_var(setting('notifications_enabled', true), FILTER_VALIDATE_BOOL)
                || ! filter_var(setting('order_phone_alerts_enabled', false), FILTER_VALIDATE_BOOL)
                || ! filter_var(setting('whatsapp_enabled', false), FILTER_VALIDATE_BOOL)) {
                return;
            }

            $order = Order::query()->withoutGlobalScopes()
                ->with(['branch.tenant', 'products.product'])
                ->whereKey($this->orderId)
                ->whereHas('branch', fn ($query) => $query->where('tenant_id', $this->tenantId))
                ->first();
            if (! $order) {
                return;
            }

            $template = collect((array) setting('whatsapp_templates', []))
                ->first(fn ($item) => is_array($item)
                    && ($item['event'] ?? null) === $this->templateEvent
                    && ($item['is_active'] ?? false)
                    && array_values((array) ($item['variables'] ?? []))
                        === ['order_number', 'branch_name', 'reason', 'order_link']);
            if (! $template) {
                Log::warning('Admin delivery WhatsApp alert template is unavailable.', [
                    'tenant_id' => $this->tenantId, 'order_id' => $this->orderId,
                    'alert_key' => $this->alertKey, 'template_event' => $this->templateEvent,
                ]);

                return;
            }

            $variables = (array) ($template['variables'] ?? []);
            $parameters = [
                'order_number' => (string) ($order->reference_no ?: $order->order_number),
                'branch_name' => $order->branch?->name ?: 'Restaurant',
                'reason' => str($this->summary)->squish()->limit(500)->toString(),
            ];
            if (in_array('order_link', $variables, true)) {
                $domain = strtolower(trim((string) $order->branch?->tenant?->domain));
                if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    Log::warning('Admin delivery WhatsApp link is unavailable.', [
                        'tenant_id' => $this->tenantId, 'order_id' => $this->orderId, 'alert_key' => $this->alertKey,
                    ]);

                    return;
                }
                $parameters['order_link'] = rtrim((string) config('app.url'), '/')
                    .'/v1/staff/order-open/'.rawurlencode((string) $order->reference_no);
            }
            $parameters = collect($variables)->mapWithKeys(fn ($key) => [$key => $parameters[$key] ?? ''])->all();

            foreach (array_unique((array) setting('order_phone_alert_numbers', [])) as $number) {
                if (! is_string($number) || ! preg_match('/^\+[1-9][0-9]{7,14}$/', $number)) {
                    continue;
                }
                $dedupe = 'staff-delivery-alert:'.$this->tenantId.':'.$this->orderId.':'.$this->alertKey.':'.hash('sha256', $number);
                if (! Cache::add($dedupe, true, now()->addDays(30))) {
                    continue;
                }
                SendWhatsAppMessageJob::dispatch(
                    $number,
                    (string) ($template['template_id'] ?? $template['id']),
                    $parameters,
                    [
                        'tenant_id' => $this->tenantId,
                        'branch_id' => $order->branch_id,
                        'audience' => 'staff_delivery_action',
                        'campaign_id' => 'delivery-action:'.$order->id.':'.$this->alertKey,
                    ],
                );
            }
        } finally {
            $context->setId($previous);
            $settings->refreshSettingBinding();
        }
    }
}
