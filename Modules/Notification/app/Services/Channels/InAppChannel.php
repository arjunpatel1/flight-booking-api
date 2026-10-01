<?php

namespace Modules\Notification\Services\Channels;

class InAppChannel implements NotificationChannelInterface
{
    public function send(string $recipient, array $payload): array
    {
        return ["queued" => true, "channel" => "in_app", "recipient" => $recipient, "payload" => $payload];
    }
}
