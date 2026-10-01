<?php

namespace Modules\Notification\Services\WhatsApp\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderInterface;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use RuntimeException;

class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    public function sendTemplate(string $recipient, string $template, array $parameters = []): array
    {
        $token = setting('whatsapp_meta_access_token');
        $phoneNumberId = setting('whatsapp_meta_phone_number_id');
        if (blank($token) || blank($phoneNumberId)) {
            throw new RuntimeException('Meta WhatsApp Cloud API is not fully configured.');
        }

        $templateConfig = collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))->first(
            fn ($item) => is_array($item) && in_array($template, array_filter([$item['id'] ?? null, $item['template_id'] ?? null, $item['name'] ?? null]), true)
        );
        if (! is_array($templateConfig)) {
            throw new RuntimeException("WhatsApp template [{$template}] is not configured.");
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateConfig['template_id'] ?? $template,
                'language' => ['code' => $templateConfig['language_code'] ?? 'en'],
                'components' => $this->components($templateConfig, $parameters),
            ],
        ];
        $version = preg_replace('/[^0-9.]/', '', (string) setting('whatsapp_meta_graph_version', '23.0')) ?: '23.0';
        $response = Http::withToken($token)->acceptJson()->post("https://graph.facebook.com/v{$version}/{$phoneNumberId}/messages", $payload);
        if ($response->failed()) {
            throw new RuntimeException("Meta WhatsApp request failed with status {$response->status()}.");
        }

        return [
            "queued" => true,
            "provider" => "meta",
            "recipient" => $recipient,
            "template" => $template,
            "request_payload" => $payload,
            "status" => $response->status(),
            "response" => $response->json(),
        ];
    }

    public function health(): array
    {
        return [
            "provider" => "meta",
            "configured" => filled(setting('whatsapp_meta_access_token'))
                && filled(setting('whatsapp_meta_phone_number_id')),
        ];
    }

    private function components(array $config, array $parameters): array
    {
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
                $quickReply = ($matches[1] ?? '') === 'quick_reply';
                if (! $quickReply && filter_var((string) $value, FILTER_VALIDATE_URL)) {
                    $button = collect($config['buttons'] ?? [])->first(fn ($item) => (int) ($item['index'] ?? -1) === ((int) $matches[2]) - 1);
                    $prefix = preg_replace('/\{\{\s*[0-9]+\s*\}\}.*/', '', (string) ($button['url'] ?? ''));
                    if ($prefix !== '' && str_starts_with((string) $value, $prefix)) {
                        $value = substr((string) $value, strlen($prefix));
                    }
                }
                $buttons[] = [
                    'type' => 'button',
                    'sub_type' => $quickReply ? 'quick_reply' : 'url',
                    'index' => (string) max(0, ((int) $matches[2]) - 1),
                    'parameters' => [[
                        'type' => $quickReply ? 'payload' : 'text',
                        $quickReply ? 'payload' : 'text' => (string) $value,
                    ]],
                ];
                continue;
            }

            $body[] = ['type' => 'text', 'text' => (string) $value];
        }

        return [...($body === [] ? [] : [['type' => 'body', 'parameters' => $body]]), ...$buttons];
    }
}
