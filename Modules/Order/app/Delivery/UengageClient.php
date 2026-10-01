<?php

namespace Modules\Order\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Order\Models\DeliveryProviderApiLog;

/** Flash Open API v1.3 HTTP boundary. Never logs request headers or customer payloads. */
final class UengageClient
{
    public function serviceability(string $token, string $storeId, DeliveryLocation $pickup, DeliveryLocation $dropoff, ?string $orderReference = null): array
    {
        return $this->post($token, '/getServiceability', [
            'store_id' => $storeId,
            'pickupDetails' => ['latitude' => $pickup->latitude, 'longitude' => $pickup->longitude],
            'dropDetails' => ['latitude' => $dropoff->latitude, 'longitude' => $dropoff->longitude],
        ], $orderReference);
    }

    /** Documented for controlled manual/sandbox use. Automatic retries are prohibited. */
    public function createTask(string $token, array $payload): array
    {
        if (! config('delivery.booking_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_BOOKING_DISABLED');
        }
        foreach (['storeId', 'order_details', 'pickup_details', 'drop_details'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
            }
        }
        foreach (['order_total', 'paid', 'vendor_order_id', 'order_source'] as $required) {
            if (! array_key_exists($required, (array) $payload['order_details'])) {
                throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
            }
        }
        foreach (['name', 'contact_number', 'latitude', 'longitude', 'address', 'city'] as $required) {
            if (! array_key_exists($required, (array) $payload['pickup_details'])
                || ! array_key_exists($required, (array) $payload['drop_details'])) {
                throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
            }
        }
        $body = $this->post($token, '/createTask', $payload);
        if (! is_bool($body['status'] ?? null)
            || (($body['status'] ?? false) && (! $this->boundedString($body['taskId'] ?? null, 191)
                || ! $this->boundedString($body['Status_code'] ?? null, 80)))) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        return $body;
    }

    public function trackTaskStatus(string $token, string $storeId, string $taskId): array
    {
        $body = $this->post($token, '/trackTaskStatus', ['storeId' => $storeId, 'taskId' => $taskId]);
        if (! is_bool($body['status'] ?? null) || ! $this->boundedString($body['status_code'] ?? null, 80)
            || (($body['status'] ?? false) && ! $this->boundedString(data_get($body, 'data.taskId'), 191))) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        return $body;
    }

    public function cancelTask(string $token, string $storeId, string $taskId): array
    {
        if (! config('delivery.cancellation_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_CANCELLATION_DISABLED');
        }
        $body = $this->post($token, '/cancelTask', ['storeId' => $storeId, 'taskId' => $taskId]);
        if (! is_bool($body['status'] ?? null) || ! $this->boundedString($body['status_code'] ?? null, 80)) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        return $body;
    }

    private function post(string $token, string $path, array $payload, ?string $logOrderReference = null): array
    {
        $startedAt = microtime(true);
        if ($token === '') {
            $this->record($path, $payload, null, false, 'PROVIDER_NOT_CONFIGURED', $startedAt, $logOrderReference);
            throw new ProviderUnavailable('PROVIDER_NOT_CONFIGURED');
        }
        try {
            $response = $this->request($token)->post($path, $payload);
        } catch (ConnectionException $exception) {
            $code = str_contains(strtolower($exception->getMessage()), 'timed out') ? 'PROVIDER_TIMEOUT' : 'PROVIDER_NETWORK_ERROR';
            $this->record($path, $payload, null, false, $code, $startedAt, $logOrderReference);
            throw new ProviderUnavailable($code);
        }
        try {
            $this->validateResponse($response);
        } catch (ProviderUnavailable $exception) {
            $this->record($path, $payload, $response, false, $exception->getMessage(), $startedAt, $logOrderReference);
            throw $exception;
        }

        $body = $response->json();
        if (! is_array($body)) {
            $this->record($path, $payload, $response, false, 'PROVIDER_INVALID_RESPONSE', $startedAt, $logOrderReference);
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        $successful = ! in_array($body['status'] ?? true, [false, 0, '0', '400', '500'], true);
        $message = $this->providerMessage($body, $successful ? 'Accepted' : 'Provider rejected request');
        $this->record($path, $payload, $response, $successful, $message, $startedAt, $logOrderReference);
        return $body;
    }

    private function record(string $path, array $payload, ?Response $response, bool $successful, string $message, float $startedAt, ?string $logOrderReference = null): void
    {
        try {
            $body = is_array($response?->json()) ? $response->json() : [];
            $orderReference = $logOrderReference ?: data_get($payload, 'order_details.vendor_order_id');
            DeliveryProviderApiLog::query()->create([
                'provider' => 'uengage', 'environment' => (string) config('delivery.uengage.environment', 'sandbox'),
                'operation' => ltrim($path, '/'), 'endpoint' => $path, 'order_reference' => is_string($orderReference) ? $orderReference : null,
                'http_status' => $response?->status(), 'successful' => $successful,
                'provider_status' => (string) ($body['Status_code'] ?? $body['status_code'] ?? $body['status'] ?? ''),
                'message' => mb_substr($message, 0, 500), 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_summary' => array_filter(['store_id' => $payload['store_id'] ?? $payload['storeId'] ?? null, 'task_id' => $payload['taskId'] ?? null, 'order_reference' => $orderReference]),
                'response_summary' => array_filter(['status' => $body['status'] ?? null, 'status_code' => $body['Status_code'] ?? $body['status_code'] ?? null, 'task_id' => $body['taskId'] ?? data_get($body, 'data.taskId'), 'message' => $this->providerMessage($body, '')]),
            ]);
        } catch (\Throwable) {
            // Logging must never interrupt a delivery request.
        }
    }

    private function request(string $token): PendingRequest
    {
        if (! config('delivery.integration_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_INTEGRATION_DISABLED');
        }
        $environment = (string) config('delivery.uengage.environment', 'sandbox');
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        if ($environment === 'production' && ! config('delivery.uengage.production_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_PRODUCTION_DISABLED');
        }
        if ($environment === 'sandbox' && ! config('delivery.sandbox_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_SANDBOX_DISABLED');
        }
        $baseUrl = (string) config("delivery.uengage.{$environment}_base_url");
        $expectedHost = $environment === 'sandbox' ? 'riderapi-staging.uengage.in' : 'open-api.flash.uengage.in';
        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || parse_url($baseUrl, PHP_URL_HOST) !== $expectedHost) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()->asJson()
            ->withHeaders(['access-token' => $token])
            // Do not allow a provider redirect to bypass the fixed host boundary.
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('delivery.uengage.connect_timeout', 5))
            ->timeout((int) config('delivery.uengage.timeout', 15));
    }

    private function validateResponse(Response $response): void
    {
        // Flash reports authentication failures with HTTP 200 and a body status
        // of "400". Observed shapes (2026-09-22, production host):
        //   /trackTaskStatus   {"message":"Invalid Token","status":"400"}
        //   /getServiceability {"status":"400","msg":"Invalid Auth token or Store ID"}
        //   no header          {"status":"400","msg":"Invalid Auth token"}
        // Without matching the `msg` shape these surfaced as a generic
        // "invalid response" instead of a credential/store-id problem.
        // `msg` is an object for validation errors ({"msg":{"store_id":"..."}}).
        $providerMessage = $response->json('message') ?? $response->json('msg');
        $providerMessage = is_string($providerMessage) ? $providerMessage : '';
        if (in_array($response->status(), [401, 403], true)
            || $providerMessage === 'Invalid Token'
            || str_starts_with($providerMessage, 'Invalid Auth token')) {
            throw new ProviderUnavailable('PROVIDER_AUTHENTICATION_ERROR');
        }
        if ($response->clientError()) {
            throw new ProviderUnavailable('PROVIDER_4XX');
        }
        if ($response->serverError()) {
            throw new ProviderUnavailable('PROVIDER_5XX');
        }
        if (! $response->successful()) {
            throw new ProviderUnavailable('UNKNOWN_PROVIDER_RESULT');
        }
    }

    /** Convert scalar or structured provider validation messages into a safe, bounded summary. */
    public function providerMessage(array $body, string $fallback = 'Provider rejected request'): string
    {
        $value = $body['message'] ?? $body['msg'] ?? null;
        $parts = [];
        $walk = function (mixed $item, string $path = '') use (&$walk, &$parts): void {
            if (count($parts) >= 12) return;
            if (is_array($item)) {
                foreach ($item as $key => $child) {
                    $walk($child, $path === '' ? (string) $key : $path.'.'.$key);
                }
                return;
            }
            if (! is_scalar($item) || is_bool($item)) return;
            $text = trim((string) $item);
            if ($text === '') return;
            $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted email]', $text);
            $text = preg_replace('/(?<!\d)\+?\d[\d\s().-]{6,}\d(?!\d)/', '[redacted number]', $text);
            $parts[] = ($path !== '' ? $path.': ' : '').$text;
        };
        $walk($value);

        return mb_substr($parts === [] ? $fallback : implode('; ', $parts), 0, 500);
    }

    private function boundedString(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum;
    }
}
