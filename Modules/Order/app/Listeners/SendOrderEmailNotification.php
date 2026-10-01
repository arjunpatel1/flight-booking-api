<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Notification\Jobs\SendCustomerEmailJob;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use Modules\Order\Models\Order;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Order\Support\OrderFeedbackAccess;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\CustomerAppSetting;

class SendOrderEmailNotification
{
    public function handle(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): void
    {
        try {
            $this->send($event);
        } catch (\Throwable $exception) {
            // Email rendering, tenant branding and queue transport are optional
            // side effects and must never roll back the order lifecycle.
            report($exception);
        }
    }

    private function send(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): void
    {
        [$templateId, $order, $extra] = $this->resolve($event);
        if (!$templateId || !$order instanceof Order) return;

        // The order may arrive with a deliberately partial customer relation.
        // Explicitly reload fields consumed below instead of relying on
        // loadMissing(), which does not upgrade an already-loaded relation.
        $order->load([
            'customer' => fn ($query) => $query->without('roles')->select('id', 'name', 'email'),
            'branch',
        ]);
        $tenantId = (int) $order->branch?->tenant_id;
        $recipient = trim((string) $order->customer?->email);
        if (!$tenantId || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) return;

        $appSettings = CustomerAppSetting::query()->where('tenant_id', $tenantId)->first();
        $settings = $appSettings?->settings ?? [];
        if (data_get($settings, 'email_notifications_enabled', false) !== true) return;

        $template = data_get($settings, "email_templates.{$templateId}");
        if (!is_array($template) || ($template['is_active'] ?? true) !== true) return;

        $parameters = array_merge($this->parameters($order), $extra);
        $subject = $this->render((string) ($template['subject'] ?? ''), $parameters);
        $body = $this->render((string) ($template['body'] ?? ''), $parameters);
        if ($subject === '' || $body === '') return;

        $dedupeKey = "customer-email:{$tenantId}:{$order->id}:{$templateId}";
        if (!Cache::add($dedupeKey, true, now()->addDays(30))) return;

        try {
            SendCustomerEmailJob::dispatch($tenantId, $templateId, $recipient, $subject, $body, $dedupeKey);
        } catch (\Throwable $exception) {
            Cache::forget($dedupeKey);
            // Email transport/queue availability is independent from the POS
            // order lifecycle. Record the failure without converting a valid
            // status transition into an HTTP 500.
            throw $exception;
        }
    }

    private function resolve(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): array
    {
        if ($event instanceof OrderCreated) return ['order_submitted', $event->order, []];
        if ($event instanceof OrderPaid) return ['billing_sent', $event->order, [
            'invoice_number' => $event->order->reference_no,
            'bill_total' => $event->order->total->format(),
            'amount' => $event->order->total->format(),
            'payment_link' => $this->receiptLink($event->order),
        ]];
        if ($event instanceof OrderVoided) return ['order_cancelled', $event->order, ['reason' => $event->note ?: 'Cancelled']];

        return match ($event->status) {
            OrderStatus::Confirmed => ['order_accepted', $event->order, ['estimated_time' => (string) (setting('order_whatsapp_estimated_time') ?: '')]],
            OrderStatus::Preparing => ['order_preparing', $event->order, []],
            OrderStatus::Ready => ['order_ready', $event->order, []],
            OrderStatus::Completed, OrderStatus::Served => ['order_completed', $event->order, ['feedback_link' => $this->feedbackLink($event->order)]],
            OrderStatus::Cancelled => ['order_cancelled', $event->order, ['reason' => $event->note ?: 'Cancelled']],
            OrderStatus::Refunded => ['refund_processed', $event->order, [
                'amount' => method_exists($event->order, 'getRefundedAmount')
                    ? $event->order->getRefundedAmount()->format()
                    : $event->order->total->format(),
            ]],
            default => [null, $event->order, []],
        };
    }

    private function parameters(Order $order): array
    {
        return [
            'customer_name' => $order->customer?->name ?: $order->getCustomerName() ?: 'Customer',
            'restaurant_name' => setting('app_name') ?: config('app.name', 'NexDine'),
            'order_id' => (string) $order->reference_no,
            'order_total' => $order->total->format(),
            'tracking_link' => $this->orderLink($order),
            'feedback_link' => $this->feedbackLink($order),
        ];
    }

    private function orderLink(Order $order): string
    {
        $base = $this->frontendBase($order);
        $slug = (string) (OnlineMenu::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->value('slug') ?: '');

        if ($slug === '') {
            return $base.'/';
        }

        $order->loadMissing('branch');
        $token = app(CustomerTrackingToken::class)->issue(
            (int) $order->branch?->tenant_id,
            (string) $order->reference_no,
        );

        return $base.'/online-menu/'.rawurlencode($slug).'/orders/'.rawurlencode((string) $order->reference_no)
            .'?tracking_token='.rawurlencode($token);
    }

    private function receiptLink(Order $order): string
    {
        return $order->getInvoice()?->getPDFUrl() ?: $this->orderLink($order);
    }

    private function feedbackLink(Order $order): string
    {
        $base = $this->frontendBase($order);
        return $base.'/feedback/orders/'.rawurlencode((string) $order->reference_no)
            .'?source=email&token='.rawurlencode(OrderFeedbackAccess::token($order));
    }

    private function frontendBase(Order $order): string
    {
        $order->loadMissing('branch.tenant');
        $domain = trim((string) $order->branch?->tenant?->domain);
        if ($domain !== '') {
            return rtrim(preg_match('~^https?://~i', $domain) ? $domain : 'https://'.$domain, '/');
        }

        return rtrim((string) (setting('frontend_url') ?: config('app.frontend_url') ?: config('app.url')), '/');
    }

    private function render(string $value, array $parameters): string
    {
        return trim((string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            fn(array $match) => is_scalar($parameters[$match[1]] ?? null) ? (string) $parameters[$match[1]] : '',
            $value));
    }
}
