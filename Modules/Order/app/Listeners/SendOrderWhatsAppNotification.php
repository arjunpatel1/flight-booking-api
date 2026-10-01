<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Menu\Models\OnlineMenu;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use Modules\Order\Models\Order;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Order\Support\OrderFeedbackAccess;
use Modules\Order\Support\OrderPaymentPresenter;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingSender;

class SendOrderWhatsAppNotification
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SettingServiceInterface $settings,
    ) {}

    public function handle(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): void
    {
        $order = $event->order;
        $tenantId = (int) DB::table('branches')->where('id', $order->branch_id)->value('tenant_id');
        $previousTenantId = $this->tenantContext->id();
        if ($tenantId > 0) {
            $this->tenantContext->setId($tenantId);
            $this->settings->refreshSettingBinding();
        }
        try {
            $this->send($event);
        } catch (\Throwable $exception) {
            // Template resolution, tenant URLs and provider dispatch are all
            // optional side effects. None may roll back a persisted order
            // status transition.
            report($exception);
            if (app()->runningUnitTests()) {
                throw $exception;
            }
        } finally {
            $this->tenantContext->setId($previousTenantId);
            $this->settings->refreshSettingBinding();
        }
    }

    private function send(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): void
    {
        $isPaidBill = $event instanceof OrderPaid;
        [$eventKey, $order, $extra] = $this->resolve($event);
        // Native catalog orders are acknowledged by ProcessWhatsAppOrderingMessage
        // in the same conversation. Sending the legacy tenant template here
        // duplicates the receipt and omits its cancellation link.
        if ($eventKey === 'order_submitted' && $order instanceof Order
            && data_get($order->fulfilmentDetails(), 'channel') === 'whatsapp_catalog') {
            return;
        }
        // Gateway orders are created before payment so the gateway can bind to
        // an immutable order reference. Do not announce that pending shell as
        // received/confirmed. Razorpay/UPI re-emits OrderCreated after verified
        // settlement and kitchen release, which is the correct notification.
        if ($eventKey === 'order_submitted' && $order instanceof Order
            && in_array(strtolower((string) data_get($order->fulfilmentDetails(), 'payment_method', '')), ['razorpay', 'upi'], true)
            && ! $order->payment_status->isPaid()) {
            return;
        }
        // Apply controls before provider templates and conversation updates.
        if (! $this->whatsAppEnabled($order) || ! $this->eventEnabled($eventKey)) {
            return;
        }
        if ($order instanceof Order && $this->sendOrderingConversationUpdate($order, $eventKey, $extra)) {
            return;
        }
        // Order templates and courier milestone templates are separate controls.
        // The old shared gate suppressed confirmation/preparing/ready templates
        // whenever the tenant disabled only "Delivery alerts" in settings.
        if ($isPaidBill && ! setting('customer_payment_whatsapp_bill_enabled', false)) {
            return;
        }

        $template = $this->templateForEvent($eventKey, $order);

        if (blank($template) || ! $order instanceof Order) {
            return;
        }

        // Order list/query services intentionally hydrate a lightweight customer
        // relation (often only id/name). loadMissing() would keep that partial
        // model and strict Eloquent mode then throws for phone_verified_at.
        // Reload the relation with every identity field used by this listener.
        $order->load(['customer' => fn ($query) => $query
            ->without('roles')
            ->select('id', 'name', 'phone', 'phone_verified_at')]);
        // Account notifications may only use an identity the customer has
        // proved they control. This prevents typoed/recycled profile numbers
        // from receiving another customer's order and invoice details.
        if (! $order->customer?->phone_verified_at) {
            return;
        }
        $recipient = str_replace(' ', '', (string) $order->customer?->phone);

        if (blank($recipient)) {
            return;
        }

        $campaignId = $isPaidBill ? 'paid-bill:'.$order->reference_no : null;
        $tenantId = (int) $order->branch?->tenant_id;
        // Status events may be retried by the queue/broadcaster or emitted by
        // two operational paths. One semantic transition must produce exactly
        // one customer message.
        $dedupeKey = 'whatsapp:order-event:'.$tenantId.':'.$order->id.':'.$eventKey;
        if ($dedupeKey && ! Cache::add($dedupeKey, true, now()->addDays(30))) {
            return;
        }
        try {
            SendWhatsAppMessageJob::dispatch(
                $recipient,
                $template,
                array_merge($this->parameters($order), $extra, $isPaidBill ? [
                    'greeting' => (string) setting('customer_payment_whatsapp_greeting', 'Thank you for ordering with us.'),
                ] : []),
                [
                    'tenant_id' => $tenantId,
                    'branch_id' => $order->branch_id,
                    'campaign_id' => $campaignId,
                    'audience' => $isPaidBill ? 'customer_paid_bill' : 'order_status',
                ],
            );
        } catch (\Throwable $exception) {
            if ($dedupeKey) {
                Cache::forget($dedupeKey);
            }
            // Provider/network failures must never turn a successfully
            // persisted POS status transition into an HTTP 500. The WhatsApp
            // job/logging pipeline reports delivery failures independently.
            throw $exception;
        }
    }

    private function sendOrderingConversationUpdate(Order $order, ?string $eventKey, array $extra): bool
    {
        if (! $eventKey || $eventKey === 'order_submitted') {
            return false;
        }
        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()->with('conversation.assignment')
            ->where('order_id', $order->id)->first();
        if (! $session?->conversation?->assignment) {
            return false;
        }
        $dedupeKey = 'whatsapp:ordering-status:'.$order->id.':'.$eventKey;
        if (! Cache::add($dedupeKey, true, now()->addDays(30))) {
            return true;
        }
        $template = $this->templateForEvent($eventKey, $order);
        if (filled($template)) {
            try {
                SendWhatsAppMessageJob::dispatch(
                    $session->conversation->customer_phone,
                    $template,
                    array_merge($this->parameters($order), $extra),
                    [
                        'tenant_id' => (int) $order->branch?->tenant_id,
                        'branch_id' => $order->branch_id,
                        'audience' => 'whatsapp_order_status',
                        'campaign_id' => 'whatsapp-order-'.$eventKey.':'.$order->reference_no,
                    ],
                );

                return true;
            } catch (\Throwable $exception) {
                Cache::forget($dedupeKey);
                throw $exception;
            }
        }
        $message = match ($eventKey) {
            'order_accepted' => "Order {$order->reference_no} is confirmed and has been sent to the kitchen.",
            'preparing' => "Your order {$order->reference_no} is now being prepared.",
            'ready' => "Your order {$order->reference_no} is ready.",
            'completed' => "Order {$order->reference_no} is completed. Thank you for ordering with us.",
            'cancelled' => "Order {$order->reference_no} was cancelled. Reason: ".($extra['reason'] ?? 'Cancelled by restaurant'),
            'billing_sent' => "Payment successful for order {$order->reference_no}. Amount: ".($extra['bill_total'] ?? $order->total->format()).($order->status === OrderStatus::Confirmed ? ' Your order has been sent to the kitchen.' : ''),
            default => null,
        };
        if (! $message) {
            Cache::forget($dedupeKey);

            return false;
        }
        try {
            app(WhatsAppOrderingSender::class)->sendText(
                $session->conversation->assignment,
                $session->conversation->customer_phone,
                $message,
            );
        } catch (\Throwable $exception) {
            Cache::forget($dedupeKey);
            throw $exception;
        }

        return true;
    }

    private function resolve(OrderCreated|OrderUpdateStatus|OrderPaid|OrderVoided $event): array
    {
        if ($event instanceof OrderCreated) {
            return ['order_submitted', $event->order, []];
        }

        if ($event instanceof OrderPaid) {
            return ['billing_sent', $event->order, [
                'invoice_number' => $event->order->reference_no,
                'bill_total' => $event->order->total->format(),
                'payment_link' => $this->receiptLink($event->order),
                'amount' => $event->order->total->format(),
            ]];
        }

        if ($event instanceof OrderVoided) {
            return ['cancelled', $event->order, ['reason' => $this->cancellationReason($event->note)]];
        }

        return match ($event->status) {
            OrderStatus::Confirmed => ['order_accepted', $event->order, [
                'estimated_time' => (string) (setting('order_whatsapp_estimated_time') ?: '20 minutes'),
            ]],
            OrderStatus::Preparing => ['preparing', $event->order, []],
            OrderStatus::Ready => ['ready', $event->order, []],
            OrderStatus::Completed, OrderStatus::Served => ['completed', $event->order, [
                'feedback_link' => $this->feedbackLink($event->order),
                'rating_link' => $this->feedbackLink($event->order),
                'invoice_number' => $event->order->reference_no,
                'payment_link' => $this->receiptLink($event->order),
                'order_again_link' => $this->orderAgainLink($event->order),
                'feedback_reply' => 'happy:'.$event->order->reference_no,
                'order_date' => $event->order->created_at?->timezone((string) (setting('timezone') ?: config('app.timezone')))->format('d/m/Y H:i') ?: now()->format('d/m/Y H:i'),
            ]],
            OrderStatus::Cancelled => ['cancelled', $event->order, ['reason' => $this->cancellationReason($event->note)]],
            OrderStatus::Refunded => ['refund_processed', $event->order, ['amount' => $event->order->total->format()]],
            default => [null, $event->order, []],
        };
    }

    private function cancellationReason(?string $reason): string
    {
        $reason = trim((string) $reason);

        return match ($reason) {
            '', 'CUSTOMER_CANCELLED', 'WHATSAPP_CUSTOMER_CANCELLED' => 'Cancelled by customer',
            'order::orders.cancelled' => 'Cancelled by restaurant',
            default => $reason,
        };
    }

    private function whatsAppEnabled(?Order $order): bool
    {
        $configured = setting('whatsapp_enabled');
        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }
        $tenantId = (int) $order?->branch?->tenant_id;
        if (! $tenantId) {
            return false;
        }

        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->exists();
    }

    private function eventEnabled(?string $event): bool
    {
        $setting = match ($event) {
            'order_submitted' => 'customer_order_created_notification_enabled',
            'order_accepted' => 'customer_order_confirmed_notification_enabled',
            'preparing' => 'customer_order_preparing_notification_enabled',
            'ready' => 'customer_order_ready_notification_enabled',
            'cancelled' => 'customer_order_cancelled_notification_enabled',
            default => null,
        };
        if ($setting === null) {
            return true;
        }
        $value = setting($setting);

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function templateForEvent(?string $event, ?Order $order = null): ?string
    {
        if (blank($event)) {
            return null;
        }

        $eventTemplates = collect(setting('whatsapp_templates') ?: [])
            ->filter(fn ($item) => is_array($item) && ($item['event'] ?? null) === $event);
        $activeTemplates = $eventTemplates
            ->filter(fn (array $item) => ($item['is_active'] ?? true));

        if ($eventTemplates->isNotEmpty() && $activeTemplates->isEmpty()) {
            return null;
        }

        $provider = strtolower((string) ($this->managedProvider($order) ?: setting('whatsapp_provider') ?: ''));
        $requiresProviderApproval = in_array($provider, ['nexmsg', 'meta', 'msg91'], true);
        $configured = $requiresProviderApproval
            ? $activeTemplates
                ->filter(fn (array $item) => strtolower((string) ($item['provider'] ?? '')) === $provider
                    && strtolower((string) ($item['approval_status'] ?? $item['provider_status'] ?? '')) === 'approved'
                )
                ->sortByDesc(fn (array $item) => collect($item['buttons'] ?? [])->contains(fn ($button) => is_array($button)
                    && strtolower((string) ($button['type'] ?? '')) === 'url'
                    && ($button['variable'] ?? null) === 'tracking_link'
                ))
                ->first()
            : $activeTemplates->first();

        // Template-based providers reject local catalogue IDs that have not
        // been synchronized and approved in that provider account. Treat that
        // as a visible setup gap instead of queuing a message guaranteed to fail.
        if ($requiresProviderApproval && ! $configured) {
            return null;
        }

        // A tenant may map an approved provider template with a different ID
        // to a stock event. Prefer that explicit mapping over the built-in
        // catalogue entry; otherwise every tenant would silently send the
        // NexDine default instead of its approved template.
        $template = $configured ?: collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))
            ->map(fn ($item) => is_string($item) ? ['id' => $item, 'name' => $item] : $item)
            ->first(fn (array $item) => ($item['event'] ?? null) === $event && ($item['is_active'] ?? true));

        return $template['template_id'] ?? $template['id'] ?? $template['template'] ?? $template['name'] ?? null;
    }

    private function managedProvider(?Order $order): ?string
    {
        $tenantId = (int) $order?->branch?->tenant_id;
        if (! $tenantId) {
            return null;
        }

        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->latest('id')->first()?->profile?->provider;
    }

    private function parameters(Order $order): array
    {
        $order->loadMissing('products');
        $cancelToken = $this->cancelToken($order);
        $cancelLink = route('api.customer-order.cancel-link', ['token' => $cancelToken]);

        $payment = app(OrderPaymentPresenter::class)->concise($order);

        return [
            'customer_name' => $order->customer?->name ?: $order->getCustomerName() ?: (setting('order_whatsapp_customer_fallback') ?: 'Customer'),
            'restaurant_name' => setting('app_name') ?: config('app.name', 'NexDine'),
            'order_id' => $order->reference_no,
            // The approved receipt template has no dedicated payment variable.
            // Preserve its parameter count while making the total line explicit.
            'order_total' => $order->total->format().' · '.$payment,
            'order_total_numeric' => number_format((float) $order->total->amount(), 2, '.', ''),
            'order_date' => $order->created_at?->timezone((string) (setting('timezone') ?: config('app.timezone')))->format('d/m/Y H:i') ?: now()->format('d/m/Y H:i'),
            'delivered_at' => now()->timezone((string) (setting('timezone') ?: config('app.timezone')))->format('d/m/Y H:i'),
            'item_quantity' => (string) $order->products->sum('quantity'),
            // Preserve aliases used by already-approved provider templates.
            'item_qty' => (string) $order->products->sum('quantity'),
            'iteam_qty' => (string) $order->products->sum('quantity'),
            'business_name' => setting('app_name') ?: config('app.name', 'NexDine'),
            'tracking_link' => $this->trackingLink($order),
            'cancel_link' => $cancelLink,
            'cancel_token' => $cancelToken,
            'feedback_link' => $this->feedbackLink($order),
            'rating_link' => $this->feedbackLink($order),
        ];
    }

    private function feedbackLink(Order $order): string
    {
        return $this->defaultUrlTemplate(
            '/feedback/orders/'.rawurlencode((string) $order->reference_no)
                .'?source=whatsapp&token='.rawurlencode(OrderFeedbackAccess::token($order)),
            $order,
        );
    }

    private function receiptLink(Order $order): string
    {
        $invoice = $order->getInvoice();
        if ($invoice) {
            return $invoice->getPDFUrl();
        }

        return $this->trackingLink($order);
    }

    private function trackingLink(Order $order): string
    {
        $slug = $this->menuSlug($order);

        if ($slug !== '') {
            $order->loadMissing('branch');
            $token = app(CustomerTrackingToken::class)->issue(
                (int) $order->branch?->tenant_id,
                (string) $order->reference_no,
            );

            return $this->defaultUrlTemplate(
                '/online-menu/'.rawurlencode($slug).'/orders/'.rawurlencode((string) $order->reference_no)
                    .'?tracking_token='.rawurlencode($token),
                $order,
            );
        }

        return $this->defaultUrlTemplate('/', $order);
    }

    private function cancelToken(Order $order): string
    {
        $token = Str::random(48);
        Cache::put('customer-order-cancel-link:'.hash('sha256', $token), [
            'reference' => $order->reference_no,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ], now()->addMinutes(5));

        return $token;
    }

    private function orderAgainLink(Order $order): string
    {
        $slug = $this->menuSlug($order);

        return $slug
            ? $this->defaultUrlTemplate('/online-menu/'.rawurlencode((string) $slug), $order)
            : $this->defaultUrlTemplate('/', $order);
    }

    private function templatedLink(string $settingKey, Order $order): string
    {
        $template = setting($settingKey) ?: '';
        if (blank($template)) {
            return '';
        }

        return $this->replaceOrderTokens($template, $order);
    }

    private function defaultUrlTemplate(string $path, ?Order $order = null): string
    {
        $order?->loadMissing('branch.tenant');
        $tenantDomain = trim((string) $order?->branch?->tenant?->domain);
        $frontendUrl = $tenantDomain !== ''
            ? (preg_match('~^https?://~i', $tenantDomain) ? $tenantDomain : 'https://'.$tenantDomain)
            : (string) (setting('frontend_url') ?: env('FRONTEND_URL') ?: config('app.frontend_url') ?: config('app.url'));

        return rtrim($frontendUrl, '/').$path;
    }

    private function menuSlug(Order $order): string
    {
        return (string) (OnlineMenu::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->value('slug') ?: '');
    }

    private function replaceOrderTokens(string $template, Order $order): string
    {
        $replacements = [
            '{order_id}' => rawurlencode((string) $order->id),
            '{order_reference}' => rawurlencode((string) $order->reference_no),
            '{customer_id}' => rawurlencode((string) ($order->customer_id ?: '')),
            '{branch_id}' => rawurlencode((string) ($order->branch_id ?: '')),
        ];

        return strtr($template, $replacements);
    }
}
