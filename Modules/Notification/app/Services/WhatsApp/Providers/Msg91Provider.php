<?php

namespace Modules\Notification\Services\WhatsApp\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderInterface;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use RuntimeException;

class Msg91Provider implements WhatsAppProviderInterface
{
    public function sendTemplate(string $recipient, string $template, array $parameters = []): array
    {
        $templateConfig = $this->resolveTemplate($template);
        $providerTemplateId = $templateConfig['template_id'] ?? $template;
        $profile = strtolower((string) ($templateConfig['category'] ?? 'utility')) === 'marketing' ? 'marketing' : 'utility';
        $credentials = $this->credentials($profile);
        $apiUrl = $credentials['api_url'];
        $authKey = $credentials['auth_key'];
        $integratedNumber = $credentials['integrated_number'];
        $namespace = $templateConfig['namespace'] ?? null;

        if (blank($apiUrl) || blank($authKey) || blank($integratedNumber) || blank($namespace)) {
            $missing = [];
            foreach (['API URL' => $apiUrl, 'auth key' => $authKey, 'integrated number' => $integratedNumber, 'template namespace' => $namespace] as $field => $value) {
                if (blank($value)) $missing[] = $field;
            }
            throw new RuntimeException('MSG91 WhatsApp configuration is missing: '.implode(', ', $missing).'. Account ID credentials require the NexMsg provider.');
        }

        $requestPayload = [
            'integrated_number' => $integratedNumber,
            'content_type' => 'template',
            'payload' => [
                'messaging_product' => 'whatsapp',
                'type' => 'template',
                'template' => [
                    'name' => $providerTemplateId,
                    'language' => [
                        'code' => $templateConfig['language_code'] ?? 'en',
                        'policy' => 'deterministic',
                    ],
                    'namespace' => $namespace,
                    'to_and_components' => [
                        [
                            'to' => [$recipient],
                            'components' => $this->buildComponents(
                                $parameters,
                                $templateConfig['component_keys'] ?? [],
                                $templateConfig['variables'] ?? [],
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withHeaders([
            'authkey' => $authKey,
            'Content-Type' => 'application/json',
        ])->connectTimeout(5)->timeout(15)->post($apiUrl, $requestPayload);

        if ($response->failed()) {
            throw new RuntimeException("MSG91 WhatsApp request failed with HTTP {$response->status()}.");
        }

        return [
            "queued" => true,
            "provider" => "msg91",
            "profile" => $profile,
            "recipient" => $recipient,
            "template" => $template,
            "request_payload" => $requestPayload,
            "status" => $response->status(),
            "response" => $response->json() ?? $response->body(),
        ];
    }

    public function health(): array
    {
        return [
            "provider" => "msg91",
            "configured" => $this->profileConfigured('utility') && $this->profileConfigured('marketing'),
            "profiles" => [
                'utility' => ['configured' => $this->profileConfigured('utility')],
                'marketing' => ['configured' => $this->profileConfigured('marketing')],
            ],
        ];
    }

    public function credentials(string $profile): array
    {
        $reuse = $profile === 'marketing' && (bool) setting('whatsapp_msg91_marketing_reuse_utility', true);
        $prefix = $reuse ? 'utility' : $profile;

        return [
            'api_url' => setting("whatsapp_msg91_{$prefix}_api_url") ?: setting('whatsapp_msg91_api_url') ?: config('notification.providers.msg91.api_url'),
            'auth_key' => setting("whatsapp_msg91_{$prefix}_auth_key") ?: setting('whatsapp_msg91_auth_key'),
            'integrated_number' => setting("whatsapp_msg91_{$prefix}_integrated_number") ?: setting('whatsapp_msg91_integrated_number') ?: setting('whatsapp_msg91_sender_id'),
        ];
    }

    private function profileConfigured(string $profile): bool
    {
        $credentials = $this->credentials($profile);
        return filled($credentials['api_url']) && filled($credentials['auth_key']) && filled($credentials['integrated_number']);
    }

    private function resolveTemplate(string $template): array
    {
        $templateConfig = collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))
            ->map(fn($item) => is_string($item) ? ['id' => $item, 'name' => $item] : $item)
            ->first(function (array $item) use ($template) {
                return in_array($template, array_filter([
                    $item['id'] ?? null,
                    $item['template_id'] ?? null,
                    $item['name'] ?? null,
                    $item['template'] ?? null,
                ]), true);
            });

        if (blank($templateConfig)) {
            throw new RuntimeException("WhatsApp template [{$template}] is not configured.");
        }

        return $templateConfig;
    }

    private function buildComponents(array $parameters, array $componentKeys, array $variables): array
    {
        if ($this->hasNamedComponents($parameters)) {
            return collect($parameters)
                ->mapWithKeys(fn($value, $key) => [$key => $this->normalizeComponent($key, $value)])
                ->all();
        }

        $values = $this->orderedValues($parameters, $variables);
        $keys = $componentKeys ?: array_map(fn($index) => "body_" . ($index + 1), array_keys($values));

        return collect($keys)
            ->mapWithKeys(function (string $key, int $index) use ($values) {
                $value = $values[$index] ?? $values[0] ?? '';

                return [$key => $this->normalizeComponent($key, $value)];
            })
            ->all();
    }

    private function orderedValues(array $parameters, array $variables): array
    {
        if (array_is_list($parameters) || empty($variables)) {
            return array_values($parameters);
        }

        return collect($variables)
            ->map(fn(string $variable) => $parameters[$variable] ?? '')
            ->values()
            ->all();
    }

    private function hasNamedComponents(array $parameters): bool
    {
        return collect(array_keys($parameters))->contains(fn($key) => is_string($key) && preg_match('/^(body|button|header)_\d+$/', $key));
    }

    private function normalizeComponent(string $key, mixed $value): array
    {
        if (is_array($value) && isset($value['type'], $value['value'])) {
            return $value;
        }

        $component = [
            'type' => 'text',
            'value' => (string) $value,
        ];

        if (str_starts_with($key, 'button_')) {
            $component['subtype'] = str_contains($key, 'quick_reply') ? 'quick_reply' : 'url';
        }

        return $component;
    }
}
