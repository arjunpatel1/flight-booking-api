<?php

namespace Modules\WhatsAppCenter\Services\Providers;

use Illuminate\Http\Request;
use Modules\WhatsAppCenter\Contracts\WhatsAppOrderingProvider;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;

abstract class AbstractWhatsAppOrderingProvider implements WhatsAppOrderingProvider
{
    public function verifyWebhook(Request $request, WhatsAppProviderProfile $profile): void
    {
        $raw = $request->getContent();
        $secret = (string) data_get($profile->credentials, 'webhook_secret');
        $supplied = (string) ($request->header('X-Hub-Signature-256') ?: $request->header('X-Webhook-Signature'));
        $supplied = str_starts_with($supplied, 'sha256=') ? $supplied : 'sha256='.$supplied;
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        abort_unless($secret !== '' && hash_equals($expected, $supplied), 401, 'WHATSAPP_INVALID_SIGNATURE');
    }

    public function verifyChallenge(Request $request, WhatsAppProviderProfile $profile): string
    {
        abort(405, 'WHATSAPP_CHALLENGE_UNSUPPORTED');
    }

    protected function boundedText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 4096);
    }
}
