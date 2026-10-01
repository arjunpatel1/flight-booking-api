<?php

namespace Modules\WhatsAppCenter\Services\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppCenter\Data\NormalizedWebhookMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use RuntimeException;

class MetaOrderingProvider extends AbstractWhatsAppOrderingProvider
{
    public function verifyChallenge(Request $request, WhatsAppProviderProfile $profile): string
    {
        abort_unless($request->query('hub_mode') === 'subscribe', 422, 'WHATSAPP_INVALID_CHALLENGE');
        abort_unless(hash_equals(
            (string) data_get($profile->credentials, 'verify_token'),
            (string) $request->query('hub_verify_token'),
        ), 403, 'WHATSAPP_INVALID_CHALLENGE');

        return (string) $request->query('hub_challenge');
    }

    public function normalizeInbound(Request $request): NormalizedWebhookMessage
    {
        $payload = $request->json()->all();
        $message = (array) data_get($payload, 'entry.0.changes.0.value.messages.0', []);

        return new NormalizedWebhookMessage(
            providerEventId: trim((string) (data_get($message, 'id') ?: $request->header('X-Request-Id'))),
            providerPhoneId: trim((string) data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id')),
            sender: trim((string) data_get($message, 'from')),
            type: mb_substr((string) data_get($message, 'type', 'text'), 0, 30),
            text: $this->boundedText(data_get($message, 'text.body') ?: data_get($message, 'button.text') ?: data_get($message, 'interactive.button_reply.id') ?: data_get($message, 'interactive.list_reply.id')),
            safePayload: ['timestamp' => data_get($message, 'timestamp')],
            location: data_get($message, 'type') === 'location' ? [
                'latitude' => data_get($message, 'location.latitude'),
                'longitude' => data_get($message, 'location.longitude'),
                'name' => $this->boundedText(data_get($message, 'location.name')),
                'address' => $this->boundedText(data_get($message, 'location.address')),
            ] : null,
        );
    }

    public function sendText(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body): array
    {
        $response = Http::connectTimeout(5)->timeout(15)
            ->withToken((string) data_get($profile->credentials, 'access_token'))
            ->acceptJson()->post('https://graph.facebook.com/v23.0/'.$phoneNumber->provider_phone_id.'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $body],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("WhatsApp provider rejected the message with status {$response->status()}.");
        }

        return [
            'status' => $response->status(),
            'provider_message_id' => data_get($response->json(), 'messages.0.id'),
        ];
    }
}
