<?php

namespace Modules\Flight\Services\Payment;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Flight\Models\FlightPayment;
use Modules\Payment\Models\TenantPaymentGatewayConfig;

/**
 * Flight payments through the tenant's "Direct UPI Companion" gateway (the same
 * settings the admin enters under Settings → Payment Gateways).
 *
 * The customer scans a UPI QR. A payment only counts once this server has asked
 * the gateway itself, with the tenant's API key, and the gateway says PAID for
 * the exact amount and order. A signed webhook just triggers that same check.
 */
class FlightDirectUpiGateway
{
    private const REQUIRED = ['base_url', 'api_key', 'merchant_upi_account_id', 'webhook_secret'];

    public function config(int $tenantId): ?TenantPaymentGatewayConfig
    {
        $config = TenantPaymentGatewayConfig::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('provider', 'direct_upi')->where('enabled', true)->first();
        if (! $config) {
            return null;
        }
        foreach (self::REQUIRED as $field) {
            if (blank(data_get($config->credentials, $field))) {
                return null;
            }
        }

        return $config;
    }

    public function isAvailable(int $tenantId): bool
    {
        return $this->config($tenantId) !== null;
    }

    /** @return array{gateway: string, gateway_order_id: string, qr_data: ?string, intent_url: ?string, expires_at: ?string} */
    public function createOrder(FlightPayment $payment, float $amount, int $expiresInMinutes): array
    {
        $config = $this->config((int) $payment->tenant_id)
            ?? throw new DomainException('UPI payment is not set up for this agency.');
        $credentials = $config->credentials;
        $this->assertPublicHttpsUrl((string) $credentials['base_url']);

        try {
            $response = Http::connectTimeout(5)->timeout(15)->retry(2, 250, throw: false)
                ->withOptions(['allow_redirects' => false])
                ->withToken($credentials['api_key'])->acceptJson()
                // One gateway payment per flight payment, even if this call is retried.
                ->withHeaders(['Idempotency-Key' => 'flight-'.$payment->payment_reference])
                ->post(rtrim($credentials['base_url'], '/').'/payments', [
                    'merchant_order_id' => $payment->payment_reference,
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => 'INR',
                    'merchant_upi_account_id' => $credentials['merchant_upi_account_id'],
                    'expires_in_minutes' => min(30, max(1, $expiresInMinutes)),
                    'metadata' => ['source' => 'crt-flight', 'trip_reference' => $payment->trip_reference],
                ]);
        } catch (ConnectionException) {
            throw new DomainException('The UPI payment service is unreachable. Please try again shortly.');
        }

        $remote = $response->json('payment');
        if (! $response->successful() || ! is_array($remote) || blank($remote['id'] ?? null)) {
            throw new DomainException('The UPI payment service could not create a payment. Please try again.');
        }

        return [
            'gateway' => 'direct_upi',
            'gateway_order_id' => (string) $remote['id'],
            'qr_data' => data_get($remote, 'payment_method.qr_data') ?: data_get($remote, 'payment_method.intent_url'),
            'intent_url' => data_get($remote, 'payment_method.intent_url'),
            'expires_at' => $remote['expires_at'] ?? null,
        ];
    }

    /** The gateway's own view of a payment, or null when it cannot be reached. */
    public function fetch(FlightPayment $payment): ?array
    {
        $config = TenantPaymentGatewayConfig::query()->withoutGlobalScopes()
            ->where('tenant_id', $payment->tenant_id)->where('provider', 'direct_upi')->first();
        $credentials = $config?->credentials ?? [];
        if (blank($credentials['base_url'] ?? null) || blank($credentials['api_key'] ?? null) || blank($payment->gateway_order_id)) {
            return null;
        }
        $this->assertPublicHttpsUrl((string) $credentials['base_url']);

        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withToken($credentials['api_key'])->acceptJson()
                ->get(rtrim($credentials['base_url'], '/').'/payments/'.rawurlencode($payment->gateway_order_id));
        } catch (ConnectionException) {
            return null;
        }
        $remote = $response->json('payment');

        return $response->successful() && is_array($remote) ? $remote : null;
    }

    private function assertPublicHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new DomainException('The UPI payment service URL must be a public HTTPS address.');
        }
        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new DomainException('The UPI payment service URL points to a private address.');
            }
        }
    }
}
