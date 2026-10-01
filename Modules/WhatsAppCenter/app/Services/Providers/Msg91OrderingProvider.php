<?php

namespace Modules\WhatsAppCenter\Services\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppCenter\Data\NormalizedWebhookMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use RuntimeException;

class Msg91OrderingProvider extends AbstractWhatsAppOrderingProvider
{
    public function normalizeInbound(Request $request): NormalizedWebhookMessage
    {
        $payload = $request->json()->all();
        $message = (array) (data_get($payload, 'message') ?: []);

        return new NormalizedWebhookMessage(
            providerEventId: trim((string) (data_get($message, 'id') ?: data_get($payload, 'event_id') ?: $request->header('X-Request-Id'))),
            providerPhoneId: trim((string) (data_get($payload, 'integrated_number') ?: data_get($payload, 'phone_number_id'))),
            sender: trim((string) (data_get($message, 'from') ?: data_get($payload, 'from'))),
            type: mb_substr((string) (data_get($message, 'type') ?: data_get($payload, 'type', 'text')), 0, 30),
            text: $this->boundedText(data_get($message, 'text.body') ?: data_get($payload, 'text')),
            safePayload: ['timestamp' => data_get($message, 'timestamp') ?: data_get($payload, 'timestamp')],
        );
    }

    public function sendText(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body): array
    {
        $credentials = $profile->credentials ?? [];
        $response = Http::connectTimeout(5)->timeout(15)
            ->withHeaders(['authkey' => $credentials['auth_key'] ?? '', 'Content-Type' => 'application/json'])
            ->post($credentials['api_url'] ?? config('notification.providers.msg91.api_url'), [
                'integrated_number' => $phoneNumber->provider_phone_id,
                'content_type' => 'text',
                'payload' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("WhatsApp provider rejected the message with status {$response->status()}.");
        }

        return [
            'status' => $response->status(),
            'provider_message_id' => data_get($response->json(), 'message_id'),
        ];
    }
}
