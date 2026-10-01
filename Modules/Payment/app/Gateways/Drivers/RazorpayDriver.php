<?php

namespace Modules\Payment\Gateways\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Gateways\Contracts\PaymentGatewayDriver;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\GatewayPaymentStatus;

class RazorpayDriver implements PaymentGatewayDriver
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function key(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['key_id']) && ! empty($this->config['key_secret']);
    }

    public function charge(GatewayChargeRequest $request): GatewayChargeResult
    {
        $paymentId = $this->paymentId($request->metadata);

        if ($paymentId === null) {
            return GatewayChargeResult::pending(
                $request->metadata['razorpay_order_id'] ?? $request->reference,
                ['message' => 'Razorpay payment id is required before capture.'],
            );
        }

        if (! $this->signatureIsValid($request->metadata, $paymentId)) {
            return GatewayChargeResult::failed('Razorpay signature verification failed.');
        }

        $payment = $this->get("payments/{$paymentId}");
        $status = strtolower((string) ($payment['status'] ?? ''));

        if ($status === 'authorized' && (bool) ($this->config['capture_authorized'] ?? true)) {
            $payment = $this->post("payments/{$paymentId}/capture", [
                'amount' => $request->amountInMinorUnits(),
                'currency' => $request->currency,
            ]);
            $status = strtolower((string) ($payment['status'] ?? ''));
        }

        return $this->mapPayment($paymentId, $status, $payment);
    }

    public function status(string $gatewayTransactionId, array $context = []): GatewayChargeResult
    {
        $paymentId = $this->paymentId([
            'payment_id' => $gatewayTransactionId,
            ...$context,
        ]) ?? $gatewayTransactionId;

        $payment = $this->get("payments/{$paymentId}");

        return $this->mapPayment(
            $paymentId,
            strtolower((string) ($payment['status'] ?? '')),
            $payment,
        );
    }

    public function reverse(string $gatewayTransactionId, GatewayChargeRequest $original): GatewayChargeResult
    {
        if (($this->config['allow_refund'] ?? false) !== true) {
            return GatewayChargeResult::failed('Razorpay refund/reversal is disabled.');
        }

        $response = $this->post("payments/{$gatewayTransactionId}/refund", [
            'amount' => $original->amountInMinorUnits(),
            'speed' => $this->config['refund_speed'] ?? 'normal',
        ]);

        $status = strtolower((string) ($response['status'] ?? ''));

        return new GatewayChargeResult(
            status: in_array($status, ['processed', 'created', 'pending'], true)
                ? GatewayPaymentStatus::Approved
                : GatewayPaymentStatus::Failed,
            gatewayTransactionId: $gatewayTransactionId,
            message: $response['error']['description'] ?? $response['status'] ?? null,
            raw: $response,
        );
    }

    /** @param array<string,mixed> $metadata */
    private function paymentId(array $metadata): ?string
    {
        $paymentId = $metadata['razorpay_payment_id']
            ?? $metadata['payment_id']
            ?? $metadata['gateway_payment_id']
            ?? null;

        return filled($paymentId) ? (string) $paymentId : null;
    }

    /** @param array<string,mixed> $metadata */
    private function signatureIsValid(array $metadata, string $paymentId): bool
    {
        $signature = $metadata['razorpay_signature'] ?? null;
        $orderId = $metadata['razorpay_order_id'] ?? null;

        if (! $signature || ! $orderId) {
            return ! (bool) ($this->config['require_signature'] ?? false);
        }

        $expected = hash_hmac(
            'sha256',
            ((string) $orderId) . '|' . $paymentId,
            (string) $this->config['key_secret'],
        );

        return hash_equals($expected, (string) $signature);
    }

    /** @param array<string,mixed> $payment */
    private function mapPayment(string $paymentId, string $status, array $payment): GatewayChargeResult
    {
        $message = $payment['error_description']
            ?? $payment['error_reason']
            ?? $payment['status']
            ?? null;

        return new GatewayChargeResult(
            status: match ($status) {
                'captured' => GatewayPaymentStatus::Approved,
                'authorized', 'created' => GatewayPaymentStatus::Pending,
                'failed' => GatewayPaymentStatus::Declined,
                default => GatewayPaymentStatus::Failed,
            },
            gatewayTransactionId: $paymentId,
            cardScheme: isset($payment['method']) ? (string) $payment['method'] : null,
            message: $message ? (string) $message : null,
            raw: $payment,
        );
    }

    /** @return array<string,mixed> */
    private function get(string $path): array
    {
        return $this->request('get', $path);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->request('post', $path, $payload);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        try {
            $request = Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://api.razorpay.com/v1'), '/'))
                ->timeout((int) ($this->config['http_timeout_seconds'] ?? 20))
                ->withBasicAuth((string) $this->config['key_id'], (string) $this->config['key_secret'])
                ->acceptJson()
                ->asJson();

            $response = $method === 'post'
                ? $request->post('/' . ltrim($path, '/'), $payload)
                : $request->get('/' . ltrim($path, '/'));

            return $response->json() ?? [
                'status' => 'failed',
                'error_description' => 'Empty Razorpay response.',
            ];
        } catch (\Throwable $e) {
            Log::warning('Razorpay request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error_description' => $e->getMessage(),
            ];
        }
    }
}
