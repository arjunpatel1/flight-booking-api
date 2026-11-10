<?php

namespace Modules\Flight\Services\Payment;

use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightPayment;
use Modules\Flight\Services\Payment\Gateways\MockPaymentGatewayDriver;

class FlightPaymentGatewayAdapter
{
    protected array $razorpayConfig;
    protected MockPaymentGatewayDriver $mockDriver;

    public function __construct(?array $config = null)
    {
        $this->razorpayConfig = $config ?? config('payment.gateways.razorpay', []);
        $this->mockDriver = new MockPaymentGatewayDriver();
    }

    public function getMockDriver(): MockPaymentGatewayDriver
    {
        return $this->mockDriver;
    }

    public function isRazorpayConfigured(): bool
    {
        return ! empty($this->razorpayConfig['key_id']) && ! empty($this->razorpayConfig['key_secret']);
    }

    /** Live refunds move real money; they stay off until RAZORPAY_ALLOW_REFUND=true. */
    public function isGatewayRefundEnabled(): bool
    {
        return $this->isRazorpayConfigured() && ($this->razorpayConfig['allow_refund'] ?? false) === true;
    }

    /**
     * Refund (part of) a captured payment back to the customer's original
     * payment method.
     *
     * @return array{status: 'processed'|'pending'|'disabled'|'failed', gateway_refund_id: ?string, message: ?string}
     *   processed: money returned; pending: accepted by Razorpay, settling;
     *   disabled: gateway refunds are switched off (process manually);
     *   failed: Razorpay rejected or could not be reached.
     */
    public function refund(FlightPayment $payment, float $amount, string $refundReference): array
    {
        $gateway = strtolower((string) ($payment->gateway ?: ''));
        if ($gateway === 'mock') {
            return app()->environment('local', 'testing')
                ? ['status' => 'processed', 'gateway_refund_id' => 'rfnd_mock_' . Str::lower(Str::random(12)), 'message' => null]
                : ['status' => 'failed', 'gateway_refund_id' => null, 'message' => 'Mock payments cannot be refunded outside local/testing.'];
        }

        if (! $this->isGatewayRefundEnabled()) {
            return ['status' => 'disabled', 'gateway_refund_id' => null, 'message' => 'Online refunds are switched off (RAZORPAY_ALLOW_REFUND=false). Refund the customer manually from the Razorpay dashboard, then mark this refund as processed.'];
        }

        $paymentId = (string) $payment->gateway_transaction_id;
        if (! str_starts_with($paymentId, 'pay_')) {
            return ['status' => 'failed', 'gateway_refund_id' => null, 'message' => 'This payment has no Razorpay payment id, so it cannot be refunded online.'];
        }

        $baseUrl = rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/');
        try {
            $response = Http::baseUrl($baseUrl)
                ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
                ->connectTimeout(5)
                ->timeout((int) ($this->razorpayConfig['http_timeout_seconds'] ?? 20))
                ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
                ->acceptJson()
                ->asJson()
                ->post('/payments/' . $paymentId . '/refund', [
                    'amount' => (int) round($amount * 100),
                    'speed' => $this->razorpayConfig['refund_speed'] ?? 'normal',
                    // Razorpay rejects a second refund with the same receipt,
                    // so a retry after a timeout cannot refund twice.
                    'receipt' => substr($refundReference, 0, 40),
                    'notes' => ['refund_reference' => $refundReference, 'payment_reference' => (string) $payment->payment_reference],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            Log::warning('Razorpay refund request failed to connect.', ['refund_reference' => $refundReference, 'error' => $error->getMessage()]);

            return ['status' => 'failed', 'gateway_refund_id' => null, 'message' => 'Could not reach Razorpay. Retry the refund shortly.'];
        }

        $body = (array) $response->json();
        if (! $response->successful()) {
            return ['status' => 'failed', 'gateway_refund_id' => null, 'message' => (string) (data_get($body, 'error.description') ?: 'Razorpay rejected the refund.')];
        }

        return $this->mapRefundEntity($body);
    }

    /**
     * Ask Razorpay for the current state of a refund it already accepted.
     * Used when no webhook has arrived (e.g. webhooks not configured yet).
     */
    public function fetchRefund(FlightPayment $payment, string $gatewayRefundId): array
    {
        if (strtolower((string) ($payment->gateway ?: '')) === 'mock') {
            return ['status' => 'processed', 'gateway_refund_id' => $gatewayRefundId, 'message' => null];
        }
        if (! $this->isRazorpayConfigured() || ! str_starts_with($gatewayRefundId, 'rfnd_')) {
            return ['status' => 'pending', 'gateway_refund_id' => $gatewayRefundId, 'message' => 'Refund status cannot be checked online.'];
        }

        $baseUrl = rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/');
        try {
            $response = Http::baseUrl($baseUrl)
                ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
                ->connectTimeout(5)
                ->timeout((int) ($this->razorpayConfig['http_timeout_seconds'] ?? 20))
                ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
                ->acceptJson()
                ->get('/payments/' . $payment->gateway_transaction_id . '/refunds/' . $gatewayRefundId);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['status' => 'pending', 'gateway_refund_id' => $gatewayRefundId, 'message' => 'Could not reach Razorpay to check the refund. Try again shortly.'];
        }

        if (! $response->successful()) {
            return ['status' => 'pending', 'gateway_refund_id' => $gatewayRefundId, 'message' => (string) (data_get($response->json(), 'error.description') ?: 'Razorpay did not return the refund status.')];
        }

        return $this->mapRefundEntity((array) $response->json());
    }

    /** Razorpay refund entity (API response or webhook payload) -> our result shape. */
    public function mapRefundEntity(array $entity): array
    {
        $status = strtolower((string) ($entity['status'] ?? ''));

        return [
            'status' => $status === 'processed' ? 'processed' : ($status === 'failed' ? 'failed' : 'pending'),
            'gateway_refund_id' => isset($entity['id']) ? (string) $entity['id'] : null,
            'message' => $status === 'failed' ? 'Razorpay could not complete the refund to the customer\'s payment method.' : null,
        ];
    }

    /**
     * Create an upstream gateway order (Razorpay or Mock).
     *
     * @param  float|null  $amount  a trip pays every flight in one order: the trip total
     */
    public function createOrder(FlightPayment $payment, ?float $amount = null): array
    {
        $orderAmount = $amount ?? (float) $payment->amount;
        $gateway = strtolower($payment->gateway ?: 'razorpay');

        if ($gateway === 'mock' && ! app()->environment('local', 'testing')) {
            throw new DomainException('Mock payments are disabled outside local and test environments.');
        }
        if ($gateway === 'razorpay' && ! $this->isRazorpayConfigured()) {
            throw new DomainException('Razorpay is not configured. Please contact support.');
        }

        if ($gateway === 'mock') {
            $mockOrderId = 'order_mock_' . Str::random(14);
            return [
                'gateway'          => 'mock',
                'gateway_order_id' => $mockOrderId,
                'key_id'           => 'mock_key_live_test',
                'amount'           => (int) round($orderAmount * 100),
                'currency'         => $payment->currency,
            ];
        }

        // Real Razorpay API Order Creation
        $amountPaise = (int) round($orderAmount * 100);
        $baseUrl = rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/');

        try {
            $resp = Http::baseUrl($baseUrl)
                ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
                ->timeout(15)
                ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
                ->acceptJson()
                ->asJson()
                ->post('/orders', [
                    'amount'          => $amountPaise,
                    'currency'        => $payment->currency,
                    'receipt'         => $payment->payment_reference,
                    'partial_payment' => false,
                    'notes'           => [
                        'tenant_id'         => (string) $payment->tenant_id,
                        'payment_reference' => $payment->payment_reference,
                        'booking_id'        => (string) $payment->flight_booking_id,
                        'trip_reference'    => (string) ($payment->trip_reference ?? ''),
                    ],
                ]);

            if ($resp->successful() && filled($resp->json('id'))) {
                return [
                    'gateway'          => 'razorpay',
                    'gateway_order_id' => $resp->json('id'),
                    'key_id'           => $this->razorpayConfig['key_id'],
                    'amount'           => $amountPaise,
                    'currency'         => $payment->currency,
                ];
            }

            Log::error('Razorpay order creation failed', ['response' => $resp->body()]);
            throw new DomainException('Payment gateway could not initialize payment order.');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Razorpay connection error', ['message' => $e->getMessage()]);
            $detail = app()->environment('local')
                ? ' (' . class_basename($e) . ': ' . substr($e->getMessage(), 0, 240) . '; CA bundle ' . (is_file((string) ($this->razorpayConfig['ca_bundle'] ?? '')) ? 'loaded' : 'missing') . ')'
                : '';
            throw new DomainException('Payment gateway is temporarily unreachable. Please retry.' . $detail);
        }
    }

    /**
     * Verify payment signature from client callback.
     */
    public function verifySignature(FlightPayment $payment, array $payload): bool
    {
        $gateway = strtolower($payment->gateway ?: 'razorpay');
        $paymentId = $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? null;
        $orderId = $payload['razorpay_order_id'] ?? $payload['order_id'] ?? null;
        $signature = $payload['razorpay_signature'] ?? $payload['signature'] ?? null;

        if (empty($paymentId) || empty($orderId)) {
            return false;
        }

        // Mock verification
        if ($gateway === 'mock' && app()->environment('local', 'testing')) {
            if ($signature === 'invalid_signature') {
                return false;
            }
            return true;
        }

        // Razorpay HMAC SHA256 Verification
        $secret = (string) ($this->razorpayConfig['key_secret'] ?? '');
        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        return hash_equals($expected, (string) $signature);
    }

    /**
     * Confirm the payment with Razorpay and capture it before marking a booking paid.
     */
    /**
     * @param  float|null  $expectedAmount  the whole order: the trip total when one payment covers several flights
     */
    public function verifyCapturedPayment(FlightPayment $payment, array $payload, ?float $expectedAmount = null): bool
    {
        if (strtolower($payment->gateway ?: '') === 'mock') {
            return app()->environment('local', 'testing');
        }

        $paymentId = $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? null;
        $orderId = $payload['razorpay_order_id'] ?? $payload['order_id'] ?? null;
        if (! $paymentId || ! $orderId || ! $this->isRazorpayConfigured()) {
            return false;
        }

        $baseUrl = rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/');
        $request = Http::baseUrl($baseUrl)
            ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
            ->connectTimeout(5)
            ->timeout(15)
            ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
            ->acceptJson();

        try {
            $response = $request->get('/payments/' . $paymentId);
            if (! $response->successful() || ! is_array($response->json())) {
                throw new DomainException('Razorpay payment verification is temporarily unavailable.');
            }

            $details = $response->json();
            $expectedPaise = (int) round(($expectedAmount ?? (float) $payment->amount) * 100);
            if (($details['order_id'] ?? null) !== $orderId
                || (int) ($details['amount'] ?? 0) !== $expectedPaise
                || strtoupper((string) ($details['currency'] ?? '')) !== strtoupper((string) $payment->currency)) {
                throw new DomainException('Razorpay payment details do not match this booking.');
            }

            if (($details['status'] ?? null) === 'authorized'
                && (bool) ($this->razorpayConfig['capture_authorized'] ?? true)) {
                $capture = $request->asJson()->post('/payments/' . $paymentId . '/capture', [
                    'amount' => $expectedPaise,
                    'currency' => $payment->currency,
                ]);
                if (! $capture->successful() || ! is_array($capture->json())) {
                    throw new DomainException('Razorpay payment is authorized and awaiting capture.');
                }
                $details = $capture->json();
            }

            return ($details['status'] ?? null) === 'captured';
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            throw new DomainException('Razorpay payment verification is temporarily unavailable. Please retry.');
        }
    }

    /**
     * Create a Razorpay payment link. Razorpay emails/SMSes it to the customer.
     *
     * @return array{id: string, short_url: string}
     */
    public function createPaymentLink(FlightPayment $payment, array $customer, string $description, int $expiresAt): array
    {
        $response = $this->razorpayLinkRequest(fn ($request) => $request->asJson()->post('/payment_links', [
            'amount' => (int) round((float) $payment->amount * 100),
            'currency' => $payment->currency,
            'accept_partial' => false,
            'expire_by' => $expiresAt,
            'reference_id' => substr((string) $payment->payment_reference, 0, 40),
            'description' => substr($description, 0, 2048),
            'customer' => array_filter($customer),
            'notify' => ['email' => filled($customer['email'] ?? null), 'sms' => filled($customer['contact'] ?? null)],
            'reminder_enable' => false,
            'notes' => ['payment_reference' => (string) $payment->payment_reference, 'booking_id' => (string) $payment->flight_booking_id],
        ]));

        if (! filled($response['id'] ?? null) || ! filled($response['short_url'] ?? null)) {
            throw new DomainException('Razorpay did not return a payment link.');
        }

        return ['id' => (string) $response['id'], 'short_url' => (string) $response['short_url']];
    }

    /**
     * @return array{status: string, payment_id: ?string, amount_paid: int}
     */
    public function fetchPaymentLink(string $linkId): array
    {
        $response = $this->razorpayLinkRequest(fn ($request) => $request->get('/payment_links/' . $linkId));
        $payments = (array) ($response['payments'] ?? []);

        return [
            'status' => (string) ($response['status'] ?? ''),
            'payment_id' => data_get(end($payments) ?: [], 'payment_id'),
            'amount_paid' => (int) ($response['amount_paid'] ?? 0),
        ];
    }

    /** Best effort: an uncancelled link still stops working at its expiry time. */
    public function cancelPaymentLink(string $linkId): void
    {
        try {
            $this->razorpayLinkRequest(fn ($request) => $request->asJson()->post('/payment_links/' . $linkId . '/cancel'));
        } catch (DomainException $error) {
            Log::warning('Razorpay payment link could not be cancelled.', ['link' => $linkId, 'error' => $error->getMessage()]);
        }
    }

    private function razorpayLinkRequest(callable $send): array
    {
        if (! $this->isRazorpayConfigured()) {
            throw new DomainException('Razorpay is not configured, so a payment link cannot be used.');
        }

        $request = Http::baseUrl(rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/'))
            ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
            ->connectTimeout(5)
            ->timeout(15)
            ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
            ->acceptJson();

        try {
            $response = $send($request);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            throw new DomainException('Razorpay could not be reached. Please retry.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new DomainException((string) (data_get($response->json(), 'error.description') ?: 'Razorpay rejected the payment link request.'));
        }

        return $response->json();
    }

    /**
     * Ask Razorpay whether this payment's order was really paid, without relying
     * on anything from the browser. Returns the Razorpay payment id, or null.
     */
    public function findCapturedPaymentId(FlightPayment $payment, ?float $expectedAmount = null): ?string
    {
        if (strtolower($payment->gateway ?: '') !== 'razorpay' || ! $payment->gateway_order_id) {
            return null;
        }
        if (! $this->isRazorpayConfigured()) {
            throw new DomainException('Razorpay is not configured, so the payment cannot be checked.');
        }

        $baseUrl = rtrim($this->razorpayConfig['base_url'] ?? 'https://api.razorpay.com/v1', '/');

        try {
            $response = Http::baseUrl($baseUrl)
                ->withOptions(['verify' => $this->razorpayConfig['ca_bundle'] ?? true])
                ->connectTimeout(5)
                ->timeout(15)
                ->withBasicAuth($this->razorpayConfig['key_id'], $this->razorpayConfig['key_secret'])
                ->acceptJson()
                ->get('/orders/' . $payment->gateway_order_id . '/payments');
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            throw new DomainException('Razorpay could not be reached, so the payment was not checked. Please retry.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new DomainException('Razorpay could not check this payment right now. Please retry.');
        }

        foreach ((array) $response->json('items') as $item) {
            $paymentId = $item['id'] ?? null;
            if ($paymentId
                && in_array($item['status'] ?? null, ['captured', 'authorized'], true)
                && $this->verifyCapturedPayment($payment, [
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_order_id' => $payment->gateway_order_id,
                ], $expectedAmount)) {
                return $paymentId;
            }
        }

        return null;
    }

    /**
     * Verify Webhook signature.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature, ?string $secret = null): bool
    {
        $webhookSecret = $secret ?: ($this->razorpayConfig['webhook_secret'] ?? '');
        if (empty($webhookSecret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signature);
    }
}
