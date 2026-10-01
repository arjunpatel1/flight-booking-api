<?php

namespace Modules\Notification\Services\WhatsApp\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderInterface;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Models\Setting;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use RuntimeException;

class NexMsgProvider implements WhatsAppProviderInterface
{
    public function sendTemplate(string $recipient, string $template, array $parameters = []): array
    {
        $config = $this->resolveTemplate($template);
        // The endpoint is platform-controlled. Tenant users may configure only
        // their account identifier and secret, never an arbitrary outbound URL.
        $apiUrl = config('notification.providers.nexmsg.api_url');
        $managed = $this->managedCredentials();
        $accountId = ($managed['account_id'] ?? null)
            ?: setting('whatsapp_nexmsg_account_id')
            ?: $this->tenantSetting('whatsapp_nexmsg_account_id');
        $authKey = ($managed['auth_key'] ?? null)
            ?: setting('whatsapp_nexmsg_auth_key')
            ?: $this->tenantSetting('whatsapp_nexmsg_auth_key');

        if (blank($apiUrl) || blank($accountId) || blank($authKey)) {
            throw new RuntimeException('NexMsg WhatsApp requires an Account ID and Auth Key.');
        }

        $payload = [
            'accountId' => $accountId,
            'templateName' => $config['template_id'] ?? $template,
            'to' => preg_replace('/\D+/', '', $recipient),
            'languageCode' => $config['language_code'] ?? 'en',
            'components' => $this->components($config, $parameters),
        ];

        $response = Http::acceptJson()
            ->withHeaders(['authkey' => $authKey])
            ->timeout(15)
            ->retry(config('notification.suppress_send_retries', false) ? 1 : 2, 300, function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false)
            ->post($apiUrl, $payload);

        if ($response->failed()) {
            $providerMessage = $this->providerError($response->json());
            $detail = filled($providerMessage) ? ": {$providerMessage}" : '.';

            throw new RuntimeException("NexMsg rejected the message (HTTP {$response->status()}){$detail}");
        }

        return [
            'queued' => true,
            'provider' => 'nexmsg',
            'recipient' => $recipient,
            'template' => $payload['templateName'],
            'status' => $response->status(),
            'response' => $response->json() ?? ['accepted' => true],
        ];
    }

    private function providerError(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        foreach (['error.message', 'error.details', 'error.error_data.details', 'errors.0.message', 'results.0.error', 'results.0.message', 'data.message', 'message'] as $path) {
            $value = data_get($body, $path);
            if (is_scalar($value) && filled((string) $value)) {
                return str((string) $value)->squish()->limit(500)->toString();
            }
        }

        return null;
    }

    public function health(): array
    {
        $managed = $this->managedCredentials();

        return [
            'provider' => 'nexmsg',
            'configured' => (filled($managed['account_id'] ?? null) && filled($managed['auth_key'] ?? null))
                || (filled(setting('whatsapp_nexmsg_account_id')) && filled(setting('whatsapp_nexmsg_auth_key'))),
        ];
    }

    private function resolveTemplate(string $template): array
    {
        $configured = collect(setting('whatsapp_templates') ?: [])
            ->map(fn ($item) => is_string($item)
                ? ['id' => $item, 'template_id' => $item, 'name' => $item]
                : $item)
            ->filter(fn ($item) => is_array($item));
        // Provider sync can retain several approved templates for one NexDine
        // event (for example the legacy confirmation and the Track Order
        // version). Resolve the exact provider identity before matching the
        // shared internal id, otherwise merge/key operations can select the
        // wrong component contract.
        $config = $configured->first(fn (array $item) => ($item['template_id'] ?? null) === $template)
            ?? $configured->first(fn (array $item) => ($item['name'] ?? null) === $template)
            ?? $configured->first(fn (array $item) => ($item['id'] ?? null) === $template)
            ?? collect(WhatsAppTemplateCatalog::merge($configured->values()->all()))->first(fn ($item) => is_array($item)
                && in_array($template, array_filter([$item['id'] ?? null, $item['template_id'] ?? null, $item['name'] ?? null]), true));

        if (! is_array($config)) {
            throw new RuntimeException("NexMsg template [{$template}] is not configured.");
        }

        return $config;
    }

    protected function managedCredentials(): array
    {
        $tenantId = app(TenantContext::class)->id();
        if (! $tenantId && app()->bound('request')) {
            $tenantId = (int) request()->attributes->get('tenant_id');
        }
        if (! $tenantId) {
            return [];
        }

        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->latest('id')->first();
        if ($assignment?->profile?->provider !== 'nexmsg') {
            return [];
        }

        return (array) $assignment->profile->credentials;
    }

    private function tenantSetting(string $key): mixed
    {
        $tenantId = app(TenantContext::class)->id();
        if (! $tenantId && app()->bound('request')) {
            $tenantId = (int) request()->attributes->get('tenant_id');
        }
        if (! $tenantId) {
            return null;
        }

        return Setting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('branch_id')
            ->where('key', $key)
            ->first()?->payload;
    }

    private function components(array $config, array $parameters): array
    {
        if (in_array($config['id'] ?? $config['template_id'] ?? null, ['customer_login_otp', 'auth_login_verification'], true)
            && filled($parameters['otp'] ?? null)) {
            $otp = (string) $parameters['otp'];
            // NexMsg/Meta URL buttons expect only the dynamic part of the
            // approved URL (`otp{{1}}`), not the full URL and not an array.
            // Sending either of those forms is rejected with template
            // parameter error #132018.
            // The approved URL owns any required static prefix. The dynamic
            // component must remain the exact OTP, otherwise Copy Code places
            // values such as `otp123456` on the customer's clipboard.
            $buttonCode = preg_replace('/^otp/i', '', (string) ($parameters['button_code'] ?? $otp));

            return [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
                [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $buttonCode]],
                ],
            ];
        }

        $variables = $config['variables'] ?? array_keys($parameters);
        $values = array_is_list($parameters)
            ? array_values($parameters)
            : collect($variables)->map(fn ($key) => $parameters[$key] ?? '')->values()->all();
        $keys = $config['component_keys'] ?? [];
        $body = [];
        $buttons = [];

        foreach ($values as $index => $value) {
            $key = $keys[$index] ?? 'body_'.($index + 1);
            if (preg_match('/^button(?:(?:_(url|quick_reply))?)_(\d+)$/', $key, $matches)) {
                $subType = ($matches[1] ?? '') === 'quick_reply' ? 'quick_reply' : 'url';
                $buttonValue = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                if ($subType === 'url') {
                    $buttonValue = $this->providerText($this->urlButtonSuffix($buttonValue));
                }
                $buttons[] = [
                    'type' => 'button',
                    'sub_type' => $subType,
                    'index' => (string) max(0, ((int) $matches[2]) - 1),
                    'parameters' => [[
                        'type' => $subType === 'quick_reply' ? 'payload' : 'text',
                        $subType === 'quick_reply' ? 'payload' : 'text' => $buttonValue,
                    ]],
                ];
            } else {
                $body[] = ['type' => 'text', 'text' => $this->providerText((string) $value)];
            }
        }

        return [...($body === [] ? [] : [['type' => 'body', 'parameters' => $body]]), ...$buttons];
    }

    private function providerText(string $value): string
    {
        // Meta rejects control whitespace and more than four consecutive
        // spaces in template parameters (#132018). Dynamic order, rider and
        // address values can legitimately contain line breaks, so normalize
        // them at the final provider boundary instead of changing stored data.
        return str($value)->replaceMatches('/[\r\n\t\f\v]+/u', ' ')->squish()->toString();
    }

    private function urlButtonSuffix(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && preg_match('~(?:^|/)i/([0-9a-f-]{36})(?:/download)?/?$~i', $path, $matches)) {
            return $matches[1];
        }

        if (is_string($path) && preg_match('~/(?:online-menu/[^/]+/)?orders/([^/]+)(?:/show)?/?$~i', $path, $matches)) {
            $suffix = rawurldecode($matches[1]);
            $query = parse_url($value, PHP_URL_QUERY);

            return $suffix.(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        if (is_string($path) && preg_match('~/feedback/orders/([^/]+)/?$~i', $path, $matches)) {
            $suffix = rawurldecode($matches[1]);
            $query = parse_url($value, PHP_URL_QUERY);

            return $suffix.(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        if (is_string($path) && preg_match('~/v1/customer-app/order-cancel/([^/]+)/?$~i', $path, $matches)) {
            return rawurldecode($matches[1]);
        }

        if (is_string($path) && preg_match('~/v1/staff/order-open/([^/]+)/?$~i', $path, $matches)) {
            return rawurldecode($matches[1]);
        }

        return $value;
    }
}
