<?php

namespace Modules\WhatsAppCenter\Contracts;

use Illuminate\Http\Request;
use Modules\WhatsAppCenter\Data\NormalizedWebhookMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;

interface WhatsAppOrderingProvider
{
    public function verifyWebhook(Request $request, WhatsAppProviderProfile $profile): void;

    public function verifyChallenge(Request $request, WhatsAppProviderProfile $profile): string;

    public function normalizeInbound(Request $request): NormalizedWebhookMessage;

    public function sendText(
        WhatsAppProviderProfile $profile,
        WhatsAppPhoneNumber $phoneNumber,
        string $recipient,
        string $body,
    ): array;
}
