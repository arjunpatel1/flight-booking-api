<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Saas\Support\TenantContext;

class SendCustomerEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $type,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $dedupeKey,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcherService $dispatcher, TenantContext $context): void
    {
        $context->setId($this->tenantId);

        $logs = $dispatcher->dispatch($this->type, $this->recipient, [
            'subject' => $this->subject,
            'body' => $this->body,
            'restaurant_name' => setting('app_name') ?: config('app.name'),
            'logo_url' => setting('restaurant_logo_url'),
        ], [NotificationChannel::Email]);

        $log = $logs[0] ?? null;
        if (!$log || $log->status !== NotificationStatus::Sent) {
            throw new \RuntimeException('Customer email delivery failed.');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget($this->dedupeKey);
    }
}
