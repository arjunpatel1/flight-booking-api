<?php

namespace Modules\Notification\Services\WhatsApp;

interface WhatsAppProviderInterface
{
    public function sendTemplate(string $recipient, string $template, array $parameters = []): array;

    public function health(): array;
}
