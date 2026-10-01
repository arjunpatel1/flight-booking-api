<?php

namespace Modules\Notification\Services\Channels;

class SmsChannel implements NotificationChannelInterface
{
    public function send(string $recipient, array $payload): array
    {
        return ["queued" => true, "channel" => "sms", "recipient" => $recipient, "payload" => $payload];
    }
}
