<?php

namespace Modules\WhatsAppCenter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Menu\Models\OnlineMenu;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Jobs\RefundCustomerCancelledOrder;
use Modules\Order\Models\Order;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Services\DirectUpiService;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\WhatsAppCenter\Models\WhatsAppConversation;
use Modules\WhatsAppCenter\Models\WhatsAppMessage;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent;
use Modules\WhatsAppCenter\Services\WhatsAppChannelContextResolver;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingEngine;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingSender;
use Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy;

class ProcessWhatsAppOrderingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $conversationId, public int $messageId, public ?int $webhookEventId = null)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(
        WhatsAppOrderingEngine $engine,
        WhatsAppOrderingSender $sender,
        WhatsAppChannelContextResolver $contexts,
        DirectUpiService $payments,
        TenantContext $tenantContext,
        SettingServiceInterface $settings,
    ): void {
        $tenantContext->setId($this->tenantId);
        $settings->refreshSettingBinding();

        try {
            $this->process($engine, $sender, $contexts, $payments);
        } finally {
            $tenantContext->clear();
            $settings->refreshSettingBinding();
        }
    }

    private function process(
        WhatsAppOrderingEngine $engine,
        WhatsAppOrderingSender $sender,
        WhatsAppChannelContextResolver $contexts,
        DirectUpiService $payments,
    ): void {
        $processingFailure = null;
        if ($this->webhookEventId && WhatsAppWebhookEvent::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->whereKey($this->webhookEventId)
            ->where('status', 'processed')->exists()) {
            return;
        }

        $conversation = WhatsAppConversation::query()->withoutGlobalTenant()->with(['assignment.profile', 'assignment.phoneNumber'])
            ->where('tenant_id', $this->tenantId)->findOrFail($this->conversationId);
        $message = WhatsAppMessage::query()->withoutGlobalTenant()->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)->findOrFail($this->messageId);
        abort_unless((int) $conversation->assignment?->tenant_id === $this->tenantId
            && $conversation->assignment?->is_active && $conversation->assignment?->suspended_at === null, 404);
        if ($conversation->state === 'staff') {
            $this->markEvent('processed');

            return;
        }
        $context = $contexts->resolve(
            (string) $conversation->assignment->profile->uuid,
            (string) $conversation->assignment->phoneNumber->provider_phone_id,
        );
        abort_unless($context->tenantId === $this->tenantId
            && $context->assignmentId === (int) $conversation->assignment_id
            && (! $conversation->branch_id || $context->permitsBranch((int) $conversation->branch_id)), 404);

        $cancellationReply = $this->handleCancellationAction($conversation, (string) $message->body);
        if ($cancellationReply !== null) {
            $outbound = WhatsAppMessage::query()->withoutGlobalTenant()
                ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')->where('payload->in_reply_to_message_id', $message->id)->first();
            if ($outbound?->status !== 'sent') {
                $outbound ??= WhatsAppMessage::query()->withoutGlobalTenant()->create([
                    'tenant_id' => $this->tenantId, 'conversation_id' => $conversation->id, 'direction' => 'outbound',
                    'type' => 'text', 'body' => $cancellationReply, 'status' => 'sending',
                    'payload' => ['in_reply_to_message_id' => $message->id, 'event' => 'order_cancellation_action'],
                ]);
                $outbound->update(['body' => $cancellationReply, 'status' => 'sending']);
                $result = $sender->sendText($conversation->assignment, $conversation->customer_phone, $cancellationReply);
                $outbound->update(['provider_message_id' => $result['provider_message_id'] ?? null, 'status' => 'sent', 'sent_at' => now()]);
            }
            $message->update(['status' => 'processed']);
            $this->markEvent('processed');

            return;
        }

        if ($message->type === 'order') {
            // A provider timeout may happen after the NexDine order has been
            // committed. Retry only the acknowledgement in that case; never
            // import the same customer cart into a second order session.
            $existingAcknowledgement = WhatsAppMessage::query()->withoutGlobalTenant()
                ->where('tenant_id', $this->tenantId)
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->where('payload->in_reply_to_message_id', $message->id)
                ->where('payload->event', 'catalog_order_received')
                ->latest('id')
                ->first();
            if ($existingAcknowledgement) {
                if ($existingAcknowledgement->status !== 'sent') {
                    $existingAcknowledgement->update(['status' => 'sending']);
                    try {
                        $this->refreshCancelToken($existingAcknowledgement);
                        $result = $this->sendOrderAcknowledgement($sender, $conversation, $existingAcknowledgement);
                        $existingAcknowledgement->update([
                            'provider_message_id' => $result['provider_message_id'] ?? null,
                            'status' => 'sent',
                            'sent_at' => now(),
                        ]);
                    } catch (\Throwable $exception) {
                        $existingAcknowledgement->update(['status' => 'failed']);
                        $this->markEvent('failed', $this->safeFailureReason($exception));
                        throw $exception;
                    }
                }
                $message->update(['status' => 'processed']);
                $this->markEvent('processed');

                return;
            }
            try {
                $session = $engine->importCatalogOrder(
                    $conversation,
                    (string) data_get($message->payload, 'catalog_id'),
                    (array) data_get($message->payload, 'order_items', []),
                    $context,
                );
                $isReleased = $session->order?->status?->value === 'confirmed';
                $paymentsEnabled = (bool) data_get($context->capabilities, 'payments', false);
                $reply = $isReleased
                    ? "*Order Confirmed* ✅\n\nOrder: *{$session->order->reference_no}*\nTotal: *{$session->order->total->format()}*\nStatus: Sent to kitchen\n\nWe will send preparation and fulfilment updates here."
                    : ($paymentsEnabled
                        ? "*Order Received — Payment Required* 🧾\n\nOrder: *{$session->order?->reference_no}*\nTotal: *{$session->order?->total?->format()}*\nPayment: *Pending*\nKitchen: *Not sent yet*\n\nComplete payment using the secure Pay Now button. After payment is verified, your order will be sent to the kitchen automatically."
                        : "*Order Received* ✅\n\nOrder: *{$session->order?->reference_no}*\nTotal: *{$session->order?->total?->format()}*\nStatus: *Awaiting restaurant confirmation*\n\nThe restaurant will review your order before sending it to the kitchen.");
                $paymentToken = null;
                if (! $isReleased && $session->order && $paymentsEnabled) {
                    try {
                        $payment = $payments->create($context->tenantId, null, $session->order_id, 'whatsapp-'.$session->uuid.'-'.$message->id, 3);
                        $session->update(['state' => 'payment_pending']);
                        if (filled($payment->intent_url)) {
                            $paymentToken = Str::random(48);
                            Cache::put('whatsapp-order-payment-link:'.hash('sha256', $paymentToken), [
                                'reference' => $session->order->reference_no,
                                'url' => $payment->intent_url,
                                'expires_at' => now()->addMinutes(30)->toIso8601String(),
                            ], now()->addMinutes(30));
                        }
                    } catch (\Throwable $exception) {
                        Log::warning('WhatsApp payment link creation failed.', [
                            'tenant_id' => $context->tenantId, 'session_id' => $session->id, 'exception' => $exception::class,
                        ]);
                    }

                    // WhatsApp catalog checkout historically supported only Direct UPI.
                    // When a restaurant uses Razorpay, send the customer to the existing
                    // customer tracking checkout instead of silently dropping Pay Now.
                    if (! filled($paymentToken)) {
                        $paymentUrl = $this->razorpayTrackingUrl($session->order, $context->tenantId);
                        if ($paymentUrl !== null) {
                            $paymentToken = Str::random(48);
                            Cache::put('whatsapp-order-payment-link:'.hash('sha256', $paymentToken), [
                                'reference' => $session->order->reference_no,
                                'url' => $paymentUrl,
                                'expires_at' => now()->addMinutes(30)->toIso8601String(),
                            ], now()->addMinutes(30));
                            $session->update(['state' => 'payment_pending']);
                        } else {
                            $reply .= "\n\nOnline payment is temporarily unavailable. The restaurant can approve this order manually.";
                        }
                    }
                }
                $cancelToken = Str::random(48);
                if (! $isReleased) {
                    $reply .= filled($paymentToken)
                        ? "\n\nUse Pay Now to complete payment or Cancel Order within the allowed cancellation window."
                        : "\n\nUse Cancel Order within the allowed cancellation window if you no longer want this order.";
                }
                $outbound = WhatsAppMessage::query()->withoutGlobalTenant()->create([
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'type' => 'text',
                    'body' => $reply,
                    'status' => 'sending',
                    'payload' => [
                        'in_reply_to_message_id' => $message->id,
                        'event' => 'catalog_order_received',
                        'cancel_token' => $cancelToken,
                        'order_reference' => $session->order?->reference_no,
                        'receipt_details' => [
                            'customer_name' => $session->order?->customer?->name ?: $conversation->customer_name ?: 'Customer',
                            'order_reference' => $session->order?->reference_no,
                            'restaurant_name' => $session->order?->branch?->name ?: 'Restaurant',
                            'total' => $session->order?->total?->format(),
                            'has_payment_link' => filled($paymentToken),
                            'payment_required' => ! $isReleased && filled($paymentToken),
                            'payment_token' => $paymentToken,
                        ],
                    ],
                ]);
                try {
                    $this->refreshCancelToken($outbound);
                    $result = $this->sendOrderAcknowledgement($sender, $conversation, $outbound);
                    $outbound->update(['provider_message_id' => $result['provider_message_id'] ?? null, 'status' => 'sent', 'sent_at' => now()]);
                } catch (\Throwable $exception) {
                    $outbound->update(['status' => 'failed']);
                    $this->markEvent('failed', $this->safeFailureReason($exception));
                    Log::warning('WhatsApp catalog order acknowledgement failed.', [
                        'tenant_id' => $context->tenantId,
                        'conversation_id' => $conversation->id,
                        'exception' => $exception::class,
                    ]);
                    throw $exception;
                }
                $message->update(['status' => 'processed']);
                $this->markEvent('processed');
                Log::info('WhatsApp catalog order received.', [
                    'tenant_id' => $context->tenantId,
                    'branch_id' => $context->branchId,
                    'assignment_uuid' => $context->assignmentUuid,
                    'correlation_id' => $context->correlationId,
                    'order_reference' => $session->order?->reference_no,
                    'session_uuid' => $session->uuid,
                    'state' => $session->state,
                ]);

                return;
            } catch (\Throwable $exception) {
                $this->markEvent('failed', $this->safeFailureReason($exception));
                // The order may already exist. A failed acknowledgement must
                // be retried as an acknowledgement, never as a rejection.
                if (WhatsAppMessage::query()->withoutGlobalTenant()
                    ->where('tenant_id', $this->tenantId)
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')
                    ->where('payload->in_reply_to_message_id', $message->id)
                    ->where('payload->event', 'catalog_order_received')
                    ->exists()) {
                    throw $exception;
                }
                $itemError = $exception instanceof \Illuminate\Validation\ValidationException
                    ? data_get($exception->errors(), 'catalog_items.0') : null;
                $reason = $itemError ?: $this->catalogOrderFailureMessage($exception, $context->capabilities);
                $reply = "*Order Could Not Be Accepted* ⚠️\n\n".$reason;
                $outbound = WhatsAppMessage::query()->withoutGlobalTenant()
                    ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')->where('payload->in_reply_to_message_id', $message->id)->first();
                $outbound ??= WhatsAppMessage::query()->withoutGlobalTenant()->create([
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'type' => 'text',
                    'body' => $reply,
                    'status' => 'sending',
                    'payload' => ['in_reply_to_message_id' => $message->id, 'event' => 'catalog_order_rejected'],
                ]);
                if ($outbound->status !== 'sent') {
                    $outbound->update(['body' => $reply, 'status' => 'sending']);
                    try {
                        $result = $sender->sendText($conversation->assignment, $conversation->customer_phone, $reply);
                        $outbound->update(['provider_message_id' => $result['provider_message_id'] ?? null, 'status' => 'sent', 'sent_at' => now()]);
                    } catch (\Throwable $sendException) {
                        $outbound->update(['status' => 'failed']);
                        throw $sendException;
                    }
                }
                $message->update(['status' => 'rejected']);

                return;
            }
        }
        $sessionBefore = WhatsAppOrderSession::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
            ->whereNull('order_id')->where('expires_at', '>', now())->latest('id')->first();
        try {
            $reply = $message->type === 'location'
                ? $engine->handleLocation($conversation, (array) data_get($message->payload, 'location', []), $context)
                : $engine->handle($conversation, (string) $message->body, $context);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $reply = collect($exception->errors())->flatten()->first() ?: 'That action could not be completed.';
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $reply = $exception->getStatusCode() === 422
                ? ($exception->getMessage() ?: 'That action could not be completed.')
                : 'That action could not be completed. Please try again or send HUMAN for assistance.';
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp ordering command failed.', [
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'assignment_uuid' => $context->assignmentUuid,
                'correlation_id' => $context->correlationId,
                'exception' => $exception::class,
            ]);
            $processingFailure = $this->safeFailureReason($exception);
            $reply = 'That action could not be completed. Please try again or send HUMAN for assistance.';
        }
        $outbound = WhatsAppMessage::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')->where('payload->in_reply_to_message_id', $message->id)->first();
        if ($outbound?->status === 'sent') {
            $this->markEvent('processed');

            return;
        }
        $attempts = (int) data_get($outbound?->payload, 'attempts', 0) + 1;
        $sessionAfter = WhatsAppOrderSession::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
            ->whereNull('order_id')->where('expires_at', '>', now())->latest('id')->first();
        [$command] = array_pad(preg_split('/\s+/', mb_strtolower(trim((string) $message->body)), 2) ?: [], 1, '');
        $catalogReady = in_array($command, ['pickup', 'takeaway'], true)
            || ($command === 'confirm' && $sessionBefore?->state === 'waiting_delivery_confirmation');
        $deliveryRejected = $command === 'confirm'
            && $sessionBefore?->state === 'waiting_delivery_confirmation'
            && $sessionAfter?->state === 'waiting_delivery_location';
        $sendCatalog = $conversation->assignment->profile->provider === 'nexmsg'
            && $catalogReady && $sessionAfter?->state === 'browsing';
        $sendChoices = $conversation->assignment->profile->provider === 'nexmsg'
            && (WhatsAppOrderingPolicy::isGreeting((string) $message->body, $context->capabilities) || $deliveryRejected);
        $sendLocationRequest = $conversation->assignment->profile->provider === 'nexmsg'
            && ! $deliveryRejected && $sessionAfter?->state === 'waiting_delivery_location';
        $sendAddressActions = $conversation->assignment->profile->provider === 'nexmsg'
            && $sessionAfter?->state === 'waiting_delivery_confirmation';
        $sendSkipAction = $conversation->assignment->profile->provider === 'nexmsg'
            && in_array($sessionAfter?->state, ['waiting_delivery_floor', 'waiting_delivery_landmark'], true);
        $outbound ??= WhatsAppMessage::query()->withoutGlobalTenant()->create([
            'tenant_id' => $this->tenantId, 'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'type' => $sendCatalog ? 'catalog' : (($sendChoices || $sendLocationRequest || $sendAddressActions || $sendSkipAction) ? 'interactive' : 'text'), 'body' => $reply, 'status' => 'sending',
            'payload' => ['in_reply_to_message_id' => $message->id, 'attempts' => 0],
        ]);
        $outbound->update(['type' => $sendCatalog ? 'catalog' : (($sendChoices || $sendLocationRequest || $sendAddressActions || $sendSkipAction) ? 'interactive' : 'text'), 'body' => $reply, 'status' => 'sending', 'payload' => [
            'in_reply_to_message_id' => $message->id, 'attempts' => $attempts,
        ]]);
        try {
            if ($sendCatalog) {
                $result = $sender->sendCatalog(
                    $conversation->assignment,
                    $conversation->customer_phone,
                    $reply ?: 'Welcome! View our menu and order directly through WhatsApp:',
                    WhatsAppOrderingPolicy::catalogFooter($context->capabilities),
                    'whatsapp-catalog-message:'.$outbound->id,
                );
            } elseif ($sendLocationRequest) {
                $result = $sender->sendLocationRequest($conversation->assignment, $conversation->customer_phone, $reply);
            } elseif ($sendAddressActions) {
                $result = $sender->sendAddressActions($conversation->assignment, $conversation->customer_phone, $reply,
                    $processingFailure ? ['change'] : ['confirm', 'change']);
            } elseif ($sendSkipAction) {
                $result = $sender->sendAddressActions(
                    $conversation->assignment, $conversation->customer_phone, $reply, ['skip']
                );
            } elseif ($sendChoices) {
                try {
                    $result = $sender->sendOrderTypeChoices(
                        $conversation->assignment,
                        $conversation->customer_phone,
                        WhatsAppOrderingPolicy::orderTypeButtonPrompt((string) ($conversation->branch?->name ?: 'our restaurant')),
                        WhatsAppOrderingPolicy::orderTypeChoices($context->capabilities),
                    );
                } catch (\Throwable $exception) {
                    Log::warning('WhatsApp order choice buttons failed; sending text choices instead.', [
                        'tenant_id' => $this->tenantId, 'conversation_id' => $conversation->id,
                        'exception' => $exception::class,
                    ]);
                    $result = null;
                }
            } else {
                $result = $sender->sendText($conversation->assignment, $conversation->customer_phone, $reply);
            }
            if ($result === null) {
                $outbound->update(['type' => 'text']);
                $result = $sender->sendText($conversation->assignment, $conversation->customer_phone, $reply);
            }
        } catch (\Throwable $exception) {
            $outbound->update(['status' => 'failed']);
            $this->markEvent('failed', $this->safeFailureReason($exception));
            throw $exception;
        }
        $outbound->update(['provider_message_id' => $result['provider_message_id'] ?? null, 'status' => 'sent', 'sent_at' => now()]);
        $this->markEvent($processingFailure ? 'failed' : 'processed', $processingFailure);
    }

    private function handleCancellationAction(WhatsAppConversation $conversation, string $text): ?string
    {
        $command = mb_strtolower(trim($text));
        $pendingKey = 'whatsapp:cancel-confirmation:'.$this->tenantId.':'.$conversation->id;

        if (in_array($command, ['keep order', 'do not cancel', 'no'], true)) {
            if (! Cache::has($pendingKey)) {
                return null;
            }
            Cache::forget($pendingKey);

            return "*Order Kept* ✅\n\nYour order was not cancelled.";
        }

        if (in_array($command, ['confirm cancellation', 'confirm cancel', 'yes, cancel'], true)) {
            $pending = Cache::get($pendingKey);
            if (! is_array($pending) || ! filled($pending['token'] ?? null)) {
                return "*Cancellation Confirmation Expired*\n\nTap Cancel Order again to start a new confirmation.";
            }

            $result = $this->cancelWhatsAppOrder($conversation, (string) $pending['token']);
            if ($result['cancelled']) {
                Cache::forget($pendingKey);
            }

            return $result['message'];
        }

        $token = null;
        if (str_starts_with($command, 'cancel_order:')) {
            $token = trim(substr($text, strlen('cancel_order:')));
        } elseif (in_array($command, ['cancel order', 'cancel'], true)) {
            $latestReceipt = WhatsAppMessage::query()->withoutGlobalTenant()
                ->where('tenant_id', $this->tenantId)->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')->whereNotNull('payload->cancel_token')
                ->latest('id')->first();
            $token = (string) data_get($latestReceipt?->payload, 'cancel_token');
        } else {
            return null;
        }

        $order = $this->cancellableOrderForToken($conversation, $token);
        if (! $order) {
            return "*Cancellation Unavailable*\n\nThis request expired, the kitchen has started preparing the order, or the order is already closed.";
        }

        Cache::put($pendingKey, ['token' => $token, 'reference' => $order->reference_no], now()->addMinutes(5));

        return "*Cancel Order {$order->reference_no}?* ⚠️\n\nReply *CONFIRM CANCELLATION* within 5 minutes to cancel. Reply *KEEP ORDER* to continue with the order. We will recheck the kitchen status before cancelling.";
    }

    private function cancellableOrderForToken(WhatsAppConversation $conversation, string $token, bool $lock = false): ?Order
    {
        if ($token === '') {
            return null;
        }
        $link = Cache::get('customer-order-cancel-link:'.hash('sha256', $token));
        if (! is_array($link) || ! filled($link['reference'] ?? null) || ! filled($link['expires_at'] ?? null)
            || now()->gte(\Illuminate\Support\Carbon::parse($link['expires_at']))) {
            return null;
        }

        $query = Order::query()->withoutGlobalScopes()->with('products')
            ->where('reference_no', $link['reference'])
            ->whereHas('branch', fn ($branch) => $branch->where('tenant_id', $this->tenantId))
            ->whereHas('whatsAppOrderSession', fn ($session) => $session->where('conversation_id', $conversation->id));
        if ($lock) {
            $query->lockForUpdate();
        }
        $order = $query->first();
        if (! $order) {
            return null;
        }

        $available = ($order->payment_status->isUnpaid() || $order->payment_status->isPaid())
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)
            && $order->products->every(fn ($product) => in_array($product->status, [OrderProductStatus::Pending, OrderProductStatus::Cancelled], true));

        return $available ? $order : null;
    }

    /** @return array{cancelled: bool, message: string} */
    private function cancelWhatsAppOrder(WhatsAppConversation $conversation, string $token): array
    {
        $order = DB::transaction(function () use ($conversation, $token): ?Order {
            $order = $this->cancellableOrderForToken($conversation, $token, true);
            if (! $order) {
                return null;
            }
            $order->update(['status' => OrderStatus::Cancelled]);
            $order->storeStatusLog(OrderStatus::Cancelled, changedById: $order->customer_id, note: 'WHATSAPP_CUSTOMER_CANCELLED');
            event(new OrderUpdateStatus(order: $order, status: OrderStatus::Cancelled, changedById: $order->customer_id, note: 'WHATSAPP_CUSTOMER_CANCELLED'));

            return $order;
        });

        if (! $order) {
            return ['cancelled' => false, 'message' => "*Cancellation Unavailable*\n\nThe kitchen has started preparing this order, the request expired, or the order is already closed."];
        }

        if ($order->payment_status->isPaid()) {
            RefundCustomerCancelledOrder::dispatch($order->id, $this->tenantId);

            return ['cancelled' => true, 'message' => "*Order Cancelled* ✅\n\nOrder: *{$order->reference_no}*\nYour verified payment refund has been started. We will notify you when the refund is completed."];
        }

        return ['cancelled' => true, 'message' => "*Order Cancelled* ✅\n\nOrder: *{$order->reference_no}*\nNo payment refund is required."];
    }

    public function failed(\Throwable $exception): void
    {
        $this->markEvent('failed', $this->safeFailureReason($exception));
    }

    private function catalogOrderFailureMessage(\Throwable $exception, array $capabilities): string
    {
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            && $exception->getStatusCode() >= 400 && $exception->getStatusCode() < 500
            && filled($exception->getMessage())) {
            return str($exception->getMessage())->squish()->limit(500)->toString();
        }

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            $message = collect($exception->errors())->flatten()->first();
            if (filled($message)) {
                return str((string) $message)->squish()->limit(500)->toString();
            }
        }

        return WhatsAppOrderingPolicy::rejectionMessage($capabilities);
    }

    private function refreshCancelToken(WhatsAppMessage $outbound): void
    {
        $token = (string) data_get($outbound->payload, 'cancel_token');
        $reference = (string) data_get($outbound->payload, 'order_reference');
        if ($token !== '' && $reference !== '') {
            Cache::put('customer-order-cancel-link:'.hash('sha256', $token), [
                'reference' => $reference,
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ], now()->addMinutes(5));
        }
    }

    private function razorpayTrackingUrl(Order $order, int $tenantId): ?string
    {
        if (! $order->customer_id || ! (bool) setting('customer_payment_razorpay_enabled', false)) {
            return null;
        }

        $gateway = TenantPaymentGatewayConfig::query()->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'razorpay')
            ->where('enabled', true)
            ->first();
        if (! $gateway || ($gateway->branches()->count() > 0 && ! $gateway->branches()->whereKey($order->branch_id)->exists())) {
            return null;
        }

        $slug = (string) (OnlineMenu::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->value('slug') ?: '');
        $order->loadMissing('branch.tenant');
        $domain = strtolower(trim((string) $order->branch?->tenant?->domain));
        if ($slug === '' || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        $trackingToken = app(CustomerTrackingToken::class)->issue($tenantId, (string) $order->reference_no);

        return 'https://'.$domain.'/online-menu/'.rawurlencode($slug).'/orders/'
            .rawurlencode((string) $order->reference_no).'?payment=1&tracking_token='.rawurlencode($trackingToken);
    }

    private function sendOrderAcknowledgement(WhatsAppOrderingSender $sender, WhatsAppConversation $conversation, WhatsAppMessage $outbound): array
    {
        $token = (string) data_get($outbound->payload, 'cancel_token');
        $details = (array) data_get($outbound->payload, 'receipt_details', []);
        // Approved templates render secure actions as WhatsApp CTA buttons.
        // Payment receipts use two buttons; confirmed/COD receipts use Cancel only.
        if ($token !== '' && $details !== []) {
            try {
                $button = $sender->sendOrderReceiptButton(
                    $conversation->assignment, $conversation->customer_phone, $details, $token,
                );
                if ($button !== null) {
                    return $button;
                }
            } catch (\Throwable $exception) {
                Log::warning('WhatsApp order button delivery failed; sending a non-link status message instead.', [
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $conversation->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $body = (string) $outbound->body;
        $paymentToken = trim((string) data_get($details, 'payment_token'));
        if ($paymentToken !== '') {
            $paymentUrl = rtrim((string) config('app.url'), '/')
                .'/v1/customer-app/order-payment/'.rawurlencode($paymentToken);
            $body .= "\n\nPay securely (valid for 30 minutes): {$paymentUrl}";
        }

        return $sender->sendText($conversation->assignment, $conversation->customer_phone, $body);
    }

    private function markEvent(string $status, ?string $error = null): void
    {
        if (! $this->webhookEventId) {
            return;
        }
        WhatsAppWebhookEvent::query()->withoutGlobalTenant()->where('tenant_id', $this->tenantId)
            ->whereKey($this->webhookEventId)->update([
                'status' => $status,
                'processed_at' => now(),
                'error' => $error,
            ]);
    }

    private function safeFailureReason(\Throwable $exception): string
    {
        $message = preg_replace(
            '/(api[_ -]?key|auth[_ -]?key|token|secret|authorization)(["\'\s:=]+)[^,\s}]+/i',
            '$1$2[REDACTED]',
            $exception->getMessage(),
        ) ?: 'Processing failed.';

        return mb_substr(class_basename($exception).': '.$message, 0, 1000);
    }
}
