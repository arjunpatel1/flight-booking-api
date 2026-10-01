<?php

namespace Modules\Order\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderPaymentPresenter;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\Order\Support\StaffOrderAlertFormatter;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;

final class SendStaffOrderPhoneAlerts implements ShouldQueue
{
    use Queueable;

    // A timed-out send may have succeeded externally. Never automatically resend.
    public int $tries = 1;

    public function __construct(public readonly int $orderId, public readonly int $tenantId)
    {
        $this->onQueue('whatsapp')->afterCommit();
    }

    public function handle(TenantContext $context, SettingServiceInterface $settings, WhatsAppProviderFactory $factory): void
    {
        $previous = $context->id();
        $previousRetries = config('notification.suppress_send_retries', false);
        config(['notification.suppress_send_retries' => true]);
        $context->setId($this->tenantId);
        $settings->refreshSettingBinding();
        try {
            if (! filter_var(setting('notifications_enabled', true), FILTER_VALIDATE_BOOL)
                || ! filter_var(setting('order_phone_alerts_enabled', false), FILTER_VALIDATE_BOOL)
                || ! filter_var(setting('whatsapp_enabled', false), FILTER_VALIDATE_BOOL)) {
                return;
            }

            $order = Order::query()->withoutGlobalScopes()->whereKey($this->orderId)
                ->whereHas('branch', fn ($q) => $q->where('tenant_id', $this->tenantId))
                ->with(['branch.tenant', 'products.product', 'payments', 'whatsAppOrderSession', 'partnerApiOrderMapping', 'aggregatorOrderMapping.integration'])->first();
            if (! $order) {
                return;
            }
            $source = OrderSourcePresenter::make($order);
            if (! in_array($source['source_type'], (array) setting('order_phone_alert_sources', []), true)) {
                return;
            }
            $requestedTemplate = (string) setting('order_phone_alert_template_id', '');
            $compatible = collect((array) setting('whatsapp_templates', []))->filter(fn ($item) => is_array($item)
                && ($item['event'] ?? null) === 'staff_order_received'
                && ($item['is_active'] ?? true)
                && in_array($item['variables'] ?? [], [
                    ['order_number', 'source', 'branch_name', 'order_total'],
                    ['order_number', 'source', 'branch_name', 'order_total', 'order_link'],
                    ['order_number', 'source', 'items', 'branch_name', 'order_total'],
                    ['order_number', 'source', 'items', 'branch_name', 'order_total', 'order_link'],
                    ['order_number', 'source', 'items', 'payment_status', 'fulfilment', 'branch_name', 'order_total', 'ordered_at', 'order_link'],
                ], true));
            $configured = preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $requestedTemplate)
                ? $compatible->first(fn ($item) => in_array($requestedTemplate, [$item['id'] ?? null, $item['template_id'] ?? null], true))
                : null;
            // Tenant admins configure recipients and sources only. Template
            // approval and mapping remain centrally managed by NexDine.
            $configured ??= $compatible->sortByDesc(function ($item): int {
                $variables = $item['variables'] ?? [];

                return (in_array('items', $variables, true) ? 10 : 0)
                    + (in_array('payment_status', $variables, true) ? 10 : 0)
                    + (in_array('ordered_at', $variables, true) ? 5 : 0)
                    + (in_array('order_link', $variables, true) ? 1 : 0);
            })->first();
            $template = (string) ($configured['template_id'] ?? $configured['id'] ?? '');
            if (! $configured) {
                Log::warning('Staff alert template mapping is missing or incompatible.', ['tenant_id' => $this->tenantId, 'order_id' => $this->orderId]);

                return;
            }
            $hasDedicatedItems = in_array('items', $configured['variables'], true);
            $parameters = [
                'order_number' => (string) ($order->reference_no ?: $order->order_number),
                'source' => $hasDedicatedItems
                    ? str($source['source_label'].' · Payment: '.app(OrderPaymentPresenter::class)->concise($order))->squish()->limit(120)->toString()
                    : $this->orderSummary($order, $source['source_label']),
            ];
            if ($hasDedicatedItems) {
                $parameters['items'] = $this->itemSummary($order);
            }
            if (in_array('payment_status', $configured['variables'], true)) {
                $parameters['payment_status'] = app(OrderPaymentPresenter::class)->concise($order);
                $parameters['fulfilment'] = str((string) $order->type->value)->replace('_', ' ')->title()->toString();
            }
            $parameters += [
                'branch_name' => $order->branch->name,
                'order_total' => $order->total->format(),
            ];
            if (in_array('ordered_at', $configured['variables'], true)) {
                $parameters['ordered_at'] = $order->created_at
                    ->timezone((string) (setting('timezone') ?: config('app.timezone')))
                    ->format('d M Y, h:i A');
            }
            if (in_array('order_link', $configured['variables'], true)) {
                $domain = strtolower(trim((string) $order->branch->tenant?->domain));
                if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    || blank($order->reference_no)) {
                    Log::warning('Staff order link unavailable; alert not sent.', [
                        'tenant_id' => $this->tenantId, 'order_id' => $this->orderId,
                    ]);

                    return;
                }
                $parameters['order_link'] = 'https://'.$domain.'/admin/orders/'.$order->id.'/show';
            }
            $numbers = array_unique((array) setting('order_phone_alert_numbers', []));
            foreach (array_slice($numbers, 0, 10) as $number) {
                if (! is_string($number) || ! preg_match('/^\+[1-9][0-9]{7,14}$/', $number)) {
                    continue;
                }
                $key = 'staff-order-phone:'.$this->tenantId.':'.$this->orderId.':'.hash('sha256', $number);
                if (! Cache::add($key, true, now()->addDays(30))) {
                    continue;
                }
                try {
                    $context->setId($this->tenantId);
                    $settings->refreshSettingBinding();
                    (new SendWhatsAppMessageJob($number, $template, $parameters, ['tenant_id' => $this->tenantId, 'branch_id' => $order->branch_id, 'audience' => 'staff_order_alert']))->handle($factory);
                } catch (\Throwable $exception) {
                    // Keep dedupe after ambiguous failure. No customer/recipient data in this log.
                    Log::warning('Staff order phone alert failed; manual review required.', [
                        'tenant_id' => $this->tenantId, 'order_id' => $this->orderId,
                        'failure_type' => class_basename($exception),
                    ]);
                }
            }
        } finally {
            config(['notification.suppress_send_retries' => $previousRetries]);
            $context->setId($previous);
            $settings->refreshSettingBinding();
        }
    }

    private function itemSummary(Order $order): string
    {
        return app(StaffOrderAlertFormatter::class)->items($this->orderItems($order));
    }

    private function orderItems(Order $order): iterable
    {
        return $order->products->map(fn ($line) => [
            'name' => $line->product_name ?: $line->product?->name ?: 'Item',
            'quantity' => $line->quantity,
        ]);
    }

    private function orderSummary(Order $order, string $sourceLabel): string
    {
        $instructions = collect([
            $order->notes,
            data_get($order->fulfilmentDetails(), 'special_instructions'),
            data_get($order->fulfilmentDetails(), 'delivery_instructions'),
            data_get($order->fulfilmentDetails(), 'instructions'),
        ])->first(fn ($value) => filled($value));

        return app(StaffOrderAlertFormatter::class)->format(
            $sourceLabel.' · Payment: '.app(OrderPaymentPresenter::class)->concise($order),
            $this->orderItems($order),
            $order->scheduled_at,
            $instructions,
            (string) (setting('timezone') ?: config('app.timezone')),
        );
    }
}
