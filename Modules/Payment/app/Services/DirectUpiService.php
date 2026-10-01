<?php

namespace Modules\Payment\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Models\TenantPaymentGatewayEvent;
use Modules\Payment\Models\TenantPaymentSession;

class DirectUpiService
{
    public function create(int $tenantId, ?int $actorId, int $orderId, string $idempotencyKey, int $expiresIn = 10): TenantPaymentSession
    {
        $existing = TenantPaymentSession::query()->where('tenant_id', $tenantId)
            ->where('provider', 'direct_upi')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $order = Order::query()->withOutGlobalBranchPermission()->whereKey($orderId)
            ->whereIn('branch_id', DB::table('branches')->select('id')->where('tenant_id', $tenantId))->firstOrFail();
        $amount = round($order->due_amount->amount(), 2);
        throw_if($amount <= 0, ValidationException::withMessages(['order_id' => 'The order has no outstanding balance.']));
        throw_if(strtoupper($order->currency) !== 'INR', ValidationException::withMessages(['order_id' => 'Direct UPI supports INR orders only.']));

        $config = TenantPaymentGatewayConfig::query()->where('tenant_id', $tenantId)
            ->where('provider', 'direct_upi')->where('enabled', true)->firstOrFail();
        abort_unless($config->branches()->count() === 0 || $config->branches()->whereKey($order->branch_id)->exists(), 403);
        $credentials = $config->credentials ?? [];
        foreach (['base_url', 'api_key', 'merchant_upi_account_id', 'webhook_secret'] as $field) {
            throw_if(blank($credentials[$field] ?? null), ValidationException::withMessages(['gateway' => 'Direct UPI configuration is incomplete.']));
        }
        $this->assertPublicHttpsUrl($credentials['base_url']);

        $session = DB::transaction(function () use ($tenantId, $actorId, $order, $config, $idempotencyKey, $amount) {
            Order::query()->withOutGlobalBranchPermission()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $active = TenantPaymentSession::query()->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->whereIn('status', ['creating', 'created', 'pending', 'processing', 'detected', 'verification_pending'])
                ->where(fn ($query) => $query
                    ->where(fn ($inner) => $inner->whereNull('expires_at')->where('created_at', '>', now()->subMinutes(2)))
                    ->orWhere('expires_at', '>', now()))
                ->latest('id')->first();
            if ($active) {
                return $active;
            }

            return TenantPaymentSession::query()->create([
                'tenant_id' => $tenantId, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'gateway_config_id' => $config->id, 'reference' => (string) Str::uuid(),
                'provider' => 'direct_upi', 'idempotency_key' => $idempotencyKey,
                'amount' => $amount, 'currency' => 'INR', 'status' => 'creating', 'created_by' => $actorId,
            ]);
        }, 3);

        // A concurrently-created active session is the authoritative QR for
        // this balance. Returning it prevents two payable QRs for one order.
        if ($session->idempotency_key !== $idempotencyKey) {
            return $session;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->retry(2, 250, throw: false)
                ->withOptions(['allow_redirects' => false])
                ->withToken($credentials['api_key'])->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post(rtrim($credentials['base_url'], '/').'/payments', [
                    'merchant_order_id' => $session->reference,
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => 'INR',
                    'merchant_upi_account_id' => $credentials['merchant_upi_account_id'],
                    'expires_in_minutes' => min(60, max(1, $expiresIn)),
                    'metadata' => ['nexdine_order_reference' => $order->reference_no],
                ]);
        } catch (ConnectionException) {
            $session->update(['status' => 'failed']);
            throw ValidationException::withMessages(['gateway' => 'Direct UPI is currently unreachable.']);
        }

        $payment = $response->json('payment');
        if (! $response->created() || ! is_array($payment) || blank($payment['id'] ?? null)) {
            $session->update(['status' => 'failed']);
            throw ValidationException::withMessages(['gateway' => 'Direct UPI rejected the payment session.']);
        }

        $session->update([
            'provider_payment_id' => Str::limit((string) $payment['id'], 255, ''),
            'status' => strtolower((string) ($payment['status'] ?? 'pending')),
            'intent_url' => data_get($payment, 'payment_method.intent_url'),
            'qr_data' => data_get($payment, 'payment_method.qr_data'),
            'expires_at' => data_get($payment, 'expires_at'),
        ]);

        return $session->refresh();
    }

    public function processWebhook(TenantPaymentGatewayConfig $config, string $raw, array $payload): void
    {
        $eventId = Str::limit((string) ($payload['id'] ?? ''), 120, '');
        $eventType = Str::limit((string) ($payload['type'] ?? ''), 80, '');
        $providerPaymentId = (string) data_get($payload, 'data.payment_id', '');
        throw_if($eventId === '' || $eventType === '' || $providerPaymentId === '', ValidationException::withMessages(['payload' => 'Invalid webhook payload.']));

        DB::transaction(function () use ($config, $raw, $payload, $eventId, $eventType, $providerPaymentId) {
            $session = TenantPaymentSession::query()->withoutGlobalTenant()
                ->where('gateway_config_id', $config->id)->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()->first();
            DB::table('tenant_payment_gateway_events')->insertOrIgnore([
                'tenant_id' => $config->tenant_id, 'payment_session_id' => $session?->id,
                'gateway_config_id' => $config->id, 'provider_event_id' => $eventId,
                'event_type' => $eventType, 'payload_hash' => hash('sha256', $raw),
                'occurred_at' => data_get($payload, 'data.occurred_at') ?: ($payload['created_at'] ?? null),
                'processing_status' => 'received', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $event = TenantPaymentGatewayEvent::query()->withoutGlobalTenant()
                ->where('gateway_config_id', $config->id)->where('provider_event_id', $eventId)
                ->lockForUpdate()->firstOrFail();
            throw_if($event->payload_hash !== hash('sha256', $raw), ValidationException::withMessages(['payload' => 'Webhook event ID was reused with different content.']));
            if ($event->processing_status !== 'received') {
                return;
            }
            if (! $session) {
                $event->update(['processing_status' => 'rejected', 'failure_code' => 'unknown_session', 'processed_at' => now()]);

                return;
            }

            $amountMatches = number_format((float) data_get($payload, 'data.amount'), 2, '.', '') === number_format((float) $session->amount, 2, '.', '');
            $currencyMatches = strtoupper((string) data_get($payload, 'data.currency')) === $session->currency;
            $orderReferenceMatches = hash_equals($session->reference, (string) data_get($payload, 'data.merchant_order_id'));
            if (! $amountMatches || ! $currencyMatches || ! $orderReferenceMatches) {
                $session->update(['status' => 'manual_review']);
                $event->update(['processing_status' => 'rejected', 'failure_code' => 'payment_mismatch', 'processed_at' => now()]);

                return;
            }

            if ($eventType === 'payment.verified') {
                $this->finalize($session, (string) data_get($payload, 'data.transaction_reference'));
            } elseif (in_array($eventType, ['payment.failed', 'payment.cancelled'], true)) {
                $session->update(['status' => Str::after($eventType, 'payment.')]);
            }
            $event->update(['processing_status' => 'processed', 'processed_at' => now()]);
        }, 3);
    }

    private function finalize(TenantPaymentSession $session, string $transactionReference): void
    {
        if ($session->payment_id || $session->finalized_at) {
            return;
        }
        $order = Order::query()->withOutGlobalBranchPermission()->whereKey($session->order_id)->lockForUpdate()->firstOrFail();
        if (round($order->due_amount->amount(), 2) !== round((float) $session->amount, 2)) {
            $session->update(['status' => 'manual_review']);

            return;
        }
        $payment = Payment::query()->withOutGlobalBranchPermission()->create([
            'order_reference_no' => $order->reference_no, 'branch_id' => $order->branch_id,
            'order_id' => $order->id, 'transaction_id' => $transactionReference ?: $session->provider_payment_id,
            'method' => PaymentMethod::UPI->value, 'amount' => $session->amount,
            'type' => PaymentType::Payment->value, 'status' => PaymentStatus::Completed->value,
            'currency' => $session->currency, 'currency_rate' => $order->currency_rate,
            'received_at' => now(), 'gateway' => 'direct_upi',
            'gateway_transaction_id' => $session->provider_payment_id, 'processed_at' => now(),
            'meta' => ['payment_session_reference' => $session->reference],
        ]);
        $session->update(['status' => 'paid', 'transaction_reference' => $transactionReference ?: null, 'payment_id' => $payment->id, 'finalized_at' => now()]);
        // The public checkout intentionally creates UPI orders as pending.
        // Only a verified webhook may release their stock/kitchen workflow.
        $releaseToKitchen = $order->status === OrderStatus::Pending;
        if ($releaseToKitchen) {
            $order->update(['status' => OrderStatus::Confirmed, 'kitchen_display' => true]);
            $order->storeStatusLog(OrderStatus::Confirmed, note: 'UPI_PAYMENT_VERIFIED');
        }
        $order->refreshDueAmount();
        if ($releaseToKitchen) {
            $orderId = (int) $order->id;
            DB::afterCommit(function () use ($orderId): void {
                $committedOrder = Order::query()->withOutGlobalBranchPermission()->find($orderId);
                if ($committedOrder) {
                    event(new OrderCreated($committedOrder, shouldPrintKitchenTicket: true));
                }
            });
        }
    }

    private function assertPublicHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        throw_if(($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null), ValidationException::withMessages(['gateway' => 'Direct UPI URL must use HTTPS.']));
        $host = strtolower((string) $parts['host']);
        throw_if($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), ValidationException::withMessages(['gateway' => 'Direct UPI URL is not allowed.']));
        foreach (gethostbynamel($host) ?: [] as $ip) {
            throw_if(! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), ValidationException::withMessages(['gateway' => 'Direct UPI host resolves to a private or reserved address.']));
        }
    }
}
