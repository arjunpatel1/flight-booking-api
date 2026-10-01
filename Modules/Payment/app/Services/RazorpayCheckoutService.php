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
use Modules\Payment\Models\TenantPaymentSession;

class RazorpayCheckoutService
{
    public function create(int $tenantId, int $customerId, int $orderId, string $idempotencyKey): TenantPaymentSession
    {
        if ($existing = TenantPaymentSession::query()->where('tenant_id', $tenantId)->where('provider', 'razorpay')->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        abort_unless((bool) setting('customer_payment_razorpay_enabled', false), 422, 'Razorpay is disabled.');
        $order = Order::query()->withOutGlobalBranchPermission()->whereKey($orderId)->where('customer_id', $customerId)
            ->whereIn('branch_id', DB::table('branches')->select('id')->where('tenant_id', $tenantId))->firstOrFail();
        $config = TenantPaymentGatewayConfig::query()->where('tenant_id', $tenantId)->where('provider', 'razorpay')->where('enabled', true)->firstOrFail();
        abort_unless($config->branches()->count() === 0 || $config->branches()->whereKey($order->branch_id)->exists(), 403);
        $credentials = $config->credentials ?? [];
        $platform = config('payment.gateways.razorpay', []);
        abort_unless((bool) ($platform['partner_auth_enabled'] ?? false)
            && filled($platform['key_id'] ?? null) && filled($platform['key_secret'] ?? null),
            503, 'Razorpay Route is unavailable.');
        $linkedAccountId = (string) ($credentials['linked_account_id'] ?? '');
        abort_unless((bool) preg_match('/^acc_[A-Za-z0-9]{14,}$/', $linkedAccountId), 422,
            'Restaurant Razorpay linked account is not configured.');
        abort_unless(strtoupper($order->currency) === 'INR', 422, 'Razorpay checkout currently supports INR orders only.');
        $amount = round($order->due_amount->amount(), 2);
        abort_unless($amount > 0, 422, 'The order has no outstanding balance.');

        $session = DB::transaction(function () use ($tenantId, $customerId, $order, $config, $idempotencyKey, $amount) {
            Order::query()->withOutGlobalBranchPermission()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $active = TenantPaymentSession::query()->where('tenant_id', $tenantId)->where('provider', 'razorpay')
                ->where('order_id', $order->id)->whereIn('status', ['creating', 'created'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')->first();
            if ($active) {
                return $active;
            }

            return TenantPaymentSession::query()->create([
                'tenant_id' => $tenantId, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'gateway_config_id' => $config->id, 'reference' => (string) Str::uuid(), 'provider' => 'razorpay',
                'idempotency_key' => $idempotencyKey, 'amount' => $amount, 'currency' => 'INR', 'status' => 'creating', 'created_by' => $customerId,
            ]);
        }, 3);
        if ($session->idempotency_key !== $idempotencyKey || $session->provider_payment_id) {
            return $session;
        }

        try {
            $response = Http::baseUrl((string) ($platform['base_url'] ?? 'https://api.razorpay.com/v1'))->connectTimeout(5)->timeout(15)
                ->withBasicAuth($platform['key_id'], $platform['key_secret'])
                ->acceptJson()->asJson()->post('/orders', [
                    'amount' => (int) round($amount * 100), 'currency' => 'INR', 'receipt' => $session->reference,
                    'partial_payment' => false,
                    'notes' => ['tenant_id' => (string) $tenantId, 'nexdine_order_reference' => $order->reference_no],
                ]);
        } catch (ConnectionException) {
            $session->update(['status' => 'failed']);
            throw ValidationException::withMessages(['gateway' => 'Razorpay is currently unreachable. Please retry.']);
        }
        if (! $response->successful() || blank($response->json('id'))) {
            $session->update(['status' => 'failed']);
            throw ValidationException::withMessages(['gateway' => 'Razorpay could not start this payment. Please retry.']);
        }
        $session->update(['provider_payment_id' => $response->json('id'), 'status' => 'created', 'expires_at' => now()->addMinutes(30)]);

        return $session->refresh();
    }

    public function verify(TenantPaymentSession $session, array $data): array
    {
        if ($session->status === 'paid') {
            return $this->present($session);
        }
        abort_if($session->expires_at?->isPast(), 410, 'This payment link has expired. Create a new payment request.');
        TenantPaymentGatewayConfig::query()->whereKey($session->gateway_config_id)->where('enabled', true)->firstOrFail();
        $platform = config('payment.gateways.razorpay', []);
        abort_unless(hash_equals((string) $session->provider_payment_id, $data['razorpay_order_id']), 422, 'Payment order mismatch.');
        $expected = hash_hmac('sha256', $session->provider_payment_id.'|'.$data['razorpay_payment_id'], (string) ($platform['key_secret'] ?? ''));
        abort_unless(hash_equals($expected, strtolower($data['razorpay_signature'])), 422, 'Payment signature verification failed.');
        try {
            $response = Http::baseUrl((string) ($platform['base_url'] ?? 'https://api.razorpay.com/v1'))->connectTimeout(5)->timeout(15)
                ->withBasicAuth($platform['key_id'], $platform['key_secret'])->acceptJson()->get('/payments/'.$data['razorpay_payment_id']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['gateway' => 'Razorpay verification is temporarily unavailable. Please retry.']);
        }
        $gatewayPayment = $response->json();
        abort_unless($response->successful() && is_array($gatewayPayment), 422, 'Razorpay payment verification is temporarily unavailable.');
        abort_unless(($gatewayPayment['order_id'] ?? null) === $session->provider_payment_id
            && (int) ($gatewayPayment['amount'] ?? 0) === (int) round((float) $session->amount * 100)
            && strtoupper((string) ($gatewayPayment['currency'] ?? '')) === $session->currency, 422, 'Payment details do not match this order.');
        if (($gatewayPayment['status'] ?? null) === 'authorized') {
            try {
                $capture = Http::baseUrl((string) ($platform['base_url'] ?? 'https://api.razorpay.com/v1'))->connectTimeout(5)->timeout(15)
                    ->withBasicAuth($platform['key_id'], $platform['key_secret'])->acceptJson()->asJson()
                    ->post('/payments/'.$data['razorpay_payment_id'].'/capture', [
                        'amount' => (int) round((float) $session->amount * 100), 'currency' => $session->currency,
                    ]);
            } catch (ConnectionException) {
                throw ValidationException::withMessages(['gateway' => 'Payment is authorized and awaiting capture. Please retry shortly.']);
            }
            $gatewayPayment = $capture->json();
            abort_unless($capture->successful() && is_array($gatewayPayment), 409, 'Payment authorization is awaiting capture. Please retry shortly.');
        }
        abort_unless(($gatewayPayment['status'] ?? null) === 'captured', 409, 'Payment is not captured yet. Please retry verification shortly.');

        $this->finalize($session, $data['razorpay_payment_id'], $gatewayPayment);

        return $this->present($session->refresh());
    }

    public function processPartnerWebhook(array $payload): void
    {
        if (($payload['event'] ?? null) !== 'payment.captured') {
            return;
        }
        $entity = data_get($payload, 'payload.payment.entity');
        abort_unless(is_array($entity) && filled($entity['id'] ?? null) && filled($entity['order_id'] ?? null), 422,
            'Invalid Razorpay payment event.');
        $session = TenantPaymentSession::query()->withoutGlobalTenant()
            ->where('provider', 'razorpay')->where('provider_payment_id', $entity['order_id'])->first();
        if (! $session) {
            return;
        }
        TenantPaymentGatewayConfig::query()->withoutGlobalTenant()
            ->whereKey($session->gateway_config_id)->where('enabled', true)->firstOrFail();
        abort_unless((int) ($entity['amount'] ?? 0) === (int) round((float) $session->amount * 100)
            && strtoupper((string) ($entity['currency'] ?? '')) === $session->currency, 422,
            'Razorpay webhook payment mismatch.');
        $this->finalize($session, (string) $entity['id'], $entity);
    }

    private function finalize(TenantPaymentSession $session, string $paymentId, array $gatewayPayment): void
    {
        DB::transaction(function () use ($session, $paymentId, $gatewayPayment) {
            $locked = TenantPaymentSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_id) {
                return;
            }
            $order = Order::query()->withOutGlobalBranchPermission()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            abort_unless(round($order->due_amount->amount(), 2) === round((float) $locked->amount, 2), 409, 'Order balance changed; payment requires review.');
            $payment = Payment::query()->withOutGlobalBranchPermission()->create([
                'order_reference_no' => $order->reference_no, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'transaction_id' => $paymentId, 'method' => PaymentMethod::UPI->value, 'amount' => $locked->amount,
                'type' => PaymentType::Payment->value, 'status' => PaymentStatus::Completed->value, 'currency' => $locked->currency,
                'currency_rate' => $order->currency_rate, 'received_at' => now(), 'gateway' => 'razorpay',
                'gateway_transaction_id' => $paymentId, 'processed_at' => now(),
                'meta' => ['payment_session_reference' => $locked->reference, 'razorpay_method' => $gatewayPayment['method'] ?? null],
            ]);
            $locked->update(['status' => 'paid', 'transaction_reference' => $paymentId, 'payment_id' => $payment->id, 'finalized_at' => now()]);
            $releaseToKitchen = $order->status === OrderStatus::Pending;
            if ($releaseToKitchen) {
                $order->update(['status' => OrderStatus::Confirmed, 'kitchen_display' => true]);
                $order->storeStatusLog(OrderStatus::Confirmed, note: 'RAZORPAY_PAYMENT_VERIFIED');
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
        }, 3);
    }

    public function present(TenantPaymentSession $session): array
    {
        return ['reference' => $session->reference, 'razorpay_order_id' => $session->provider_payment_id,
            'key_id' => config('payment.gateways.razorpay.key_id'), 'amount' => (int) round((float) $session->amount * 100),
            'currency' => $session->currency, 'status' => $session->status];
    }
}
