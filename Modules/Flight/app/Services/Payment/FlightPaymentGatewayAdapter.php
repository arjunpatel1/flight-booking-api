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

    /**
     * Create an upstream gateway order (Razorpay or Mock).
     */
    public function createOrder(FlightPayment $payment): array
    {
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
                'amount'           => (int) round((float) $payment->amount * 100),
                'currency'         => $payment->currency,
            ];
        }

        // Real Razorpay API Order Creation
        $amountPaise = (int) round((float) $payment->amount * 100);
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
    public function verifyCapturedPayment(FlightPayment $payment, array $payload): bool
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
            $expectedAmount = (int) round((float) $payment->amount * 100);
            if (($details['order_id'] ?? null) !== $orderId
                || (int) ($details['amount'] ?? 0) !== $expectedAmount
                || strtoupper((string) ($details['currency'] ?? '')) !== strtoupper((string) $payment->currency)) {
                throw new DomainException('Razorpay payment details do not match this booking.');
            }

            if (($details['status'] ?? null) === 'authorized'
                && (bool) ($this->razorpayConfig['capture_authorized'] ?? true)) {
                $capture = $request->asJson()->post('/payments/' . $paymentId . '/capture', [
                    'amount' => $expectedAmount,
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
