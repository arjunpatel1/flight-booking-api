<?php

namespace Modules\Notification\Services\WhatsApp\Providers;

use Modules\Notification\Services\WhatsApp\WhatsAppProviderInterface;

class TwilioWhatsAppProvider implements WhatsAppProviderInterface
{
    public function sendTemplate(string $recipient, string $template, array $parameters = []): array
    {
        return [
            "queued" => true,
            "provider" => "twilio",
            "recipient" => $recipient,
            "template" => $template,
            "parameters" => $parameters,
        ];
    }

    public function health(): array
    {
        return [
            "provider" => "twilio",
            "configured" => filled(setting('whatsapp_twilio_auth_token')),
        ];
    }
}
