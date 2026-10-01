<?php

namespace Modules\Aggregator\Services\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Aggregator\Models\AggregatorIntegration;

abstract class ConfiguredAggregatorProvider implements AggregatorProviderInterface
{
    public function __construct(protected readonly string $providerName) {}

    public function menuSync(AggregatorIntegration $integration, array $payload = []): array
    {
        return $this->sendConfiguredRequest($integration, 'menu_sync', $payload);
    }

    public function orderSync(AggregatorIntegration $integration, array $payload = []): array
    {
        return $this->sendConfiguredRequest($integration, 'order_sync', $payload);
    }

    public function statusSync(AggregatorIntegration $integration, array $payload = []): array
    {
        return $this->sendConfiguredRequest($integration, 'status_sync', $payload);
    }

    public function itemAvailabilitySync(AggregatorIntegration $integration, array $payload = []): array
    {
        return $this->sendConfiguredRequest($integration, 'item_availability_sync', $payload);
    }

    public function verifyWebhook(AggregatorIntegration $integration, array $headers, array $payload): bool
    {
        if (! $this->officialContractVerified($integration)) {
            return false;
        }

        $providerSignatureHeader = 'x-'.strtolower($this->providerName).'-signature';

        $signature = $headers['x-nexdine-signature'][0]
            ?? $headers[$providerSignatureHeader][0]
            ?? null;

        if (empty($integration->webhook_secret) || empty($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', json_encode($payload), $integration->webhook_secret), $signature);
    }

    protected function sendConfiguredRequest(AggregatorIntegration $integration, string $type, array $payload = []): array
    {
        if (! $this->officialContractVerified($integration)) {
            return $this->pendingContract($type);
        }

        $endpoint = $this->endpointConfig($integration, $type);

        return $this->sendEndpointRequest($integration, $type, $endpoint, $payload);
    }

    protected function sendEndpointRequest(AggregatorIntegration $integration, string $type, array $endpoint, array $payload = []): array
    {
        if (! $this->officialContractVerified($integration)) {
            return $this->pendingContract($type);
        }

        if (blank($endpoint['url'] ?? null)) {
            return $this->pendingContract($type);
        }

        if (($endpoint['enabled'] ?? true) === false) {
            return [
                'success' => false,
                'message' => __('aggregator::aggregator.provider_endpoint_disabled'),
                'provider' => $integration->provider?->value,
                'type' => $type,
            ];
        }

        $method = strtolower($endpoint['method'] ?? 'post');
        $timeout = (int) ($endpoint['timeout'] ?? $integration->settings['timeout'] ?? 30);
        $headers = $this->renderArray([
            ...($integration->settings['api_headers'] ?? []),
            ...($endpoint['headers'] ?? []),
        ], $integration, $payload);
        $body = $this->renderArray(! empty($endpoint['body']) ? $endpoint['body'] : $payload, $integration, $payload);
        $url = $this->resolveUrl($this->renderString($endpoint['url'], $integration, $payload), $integration);

        $request = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($headers);

        $response = in_array($method, ['get', 'delete'], true)
            ? $request->{$method}($url, $body)
            : $request->{$method}($url, $body);

        return [
            'success' => $response->successful(),
            'message' => $response->successful()
                ? __('aggregator::aggregator.provider_request_success')
                : __('aggregator::aggregator.provider_request_failed'),
            'provider' => $integration->provider?->value,
            'type' => $type,
            'method' => strtoupper($method),
            'url' => $url,
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ];
    }

    protected function pendingContract(string $type): array
    {
        return [
            'success' => false,
            'message' => __('aggregator::aggregator.provider_contract_pending'),
            'provider' => strtolower($this->providerName),
            'type' => $type,
            'contract_status' => 'pending_official_contract',
        ];
    }

    protected function officialContractVerified(AggregatorIntegration $integration): bool
    {
        return filter_var($integration->settings['official_contract_verified'] ?? false, FILTER_VALIDATE_BOOL)
            || ($integration->settings['contract_status'] ?? null) === 'official_contract_verified';
    }

    protected function endpointConfig(AggregatorIntegration $integration, string $type): array
    {
        $endpoint = Arr::get($integration->settings ?: [], "api_endpoints.{$type}", []);

        if (is_string($endpoint)) {
            return ['url' => $endpoint, 'method' => 'POST'];
        }

        return is_array($endpoint) ? $endpoint : [];
    }

    private function renderArray(array $values, AggregatorIntegration $integration, array $payload): array
    {
        return collect($values)
            ->map(fn ($value) => is_array($value)
                ? $this->renderArray($value, $integration, $payload)
                : (is_string($value) ? $this->renderString($value, $integration, $payload) : $value))
            ->all();
    }

    private function resolveUrl(string $url, AggregatorIntegration $integration): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (blank($integration->base_url)) {
            return $url;
        }

        return rtrim($integration->base_url, '/').'/'.ltrim($url, '/');
    }

    private function renderString(string $value, AggregatorIntegration $integration, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', function (array $matches) use ($integration, $payload) {
            $key = $matches[1];

            return (string) (
                Arr::get($payload, $key)
                ?? Arr::get($integration->credentials ?: [], $key)
                ?? Arr::get($integration->settings ?: [], $key)
                ?? ($key === 'base_url' ? $integration->base_url : null)
                ?? $matches[0]
            );
        }, $value);
    }
}
