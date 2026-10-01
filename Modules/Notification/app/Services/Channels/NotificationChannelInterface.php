<?php

namespace Modules\Notification\Services\Channels;

interface NotificationChannelInterface
{
    public function send(string $recipient, array $payload): array;
}
