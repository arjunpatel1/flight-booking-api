<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Notification\Services\NotificationDispatcherService;

class DispatchNotificationJob implements ShouldQueue
{
    use Queueable;

    public string $queue = 'notifications';

    public function __construct(
        public readonly string $type,
        public readonly string $recipient,
        public readonly array $payload,
        public readonly array $channels = [],
    ) {
    }

    public function handle(NotificationDispatcherService $service): void
    {
        $service->dispatch($this->type, $this->recipient, $this->payload, $this->channels);
    }
}
