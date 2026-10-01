<?php

namespace Modules\Notification\Services;

use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Services\Channels\EmailChannel;
use Modules\Notification\Services\Channels\InAppChannel;
use Modules\Notification\Services\Channels\NotificationChannelInterface;
use Modules\Notification\Services\Channels\PushChannel;
use Modules\Notification\Services\Channels\SmsChannel;
use Modules\Notification\Services\Channels\WhatsAppChannel;
use Modules\Saas\Support\TenantContext;

class NotificationDispatcherService
{
    public function dispatch(string $type, string $recipient, array $payload, array $channels = []): array
    {
        $channels = $channels ?: $this->defaultChannels();

        return collect($channels)
            ->map(fn(NotificationChannel $channel) => $this->sendVia($channel, $type, $recipient, $payload))
            ->all();
    }

    private function sendVia(NotificationChannel $channel, string $type, string $recipient, array $payload): NotificationLog
    {
        $log = NotificationLog::create([
            'tenant_id' => app(TenantContext::class)->id() ?? auth()->user()?->tenantId(),
            'type' => $type,
            'channel' => $channel,
            'recipient' => $recipient,
            'payload' => $payload,
            'status' => NotificationStatus::Processing,
            'queued_at' => now(),
        ]);

        try {
            $response = $this->channel($channel)->send($recipient, $payload);
            $log->update([
                'status' => NotificationStatus::Sent,
                'response' => $response,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $log->update([
                'status' => NotificationStatus::Failed,
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
        }

        return $log;
    }

    private function defaultChannels(): array
    {
        $channels = [];

        if (setting('notifications_in_app_enabled') ?? true) {
            $channels[] = NotificationChannel::InApp;
        }

        if (setting('whatsapp_enabled') && setting('whatsapp_delivery_alerts_enabled')) {
            $channels[] = NotificationChannel::WhatsApp;
        }

        if (setting('notifications_push_enabled', false)) {
            $channels[] = NotificationChannel::Push;
        }

        return $channels;
    }

    private function channel(NotificationChannel $channel): NotificationChannelInterface
    {
        return match ($channel) {
            NotificationChannel::WhatsApp => app(WhatsAppChannel::class),
            NotificationChannel::Email => app(EmailChannel::class),
            NotificationChannel::Sms => app(SmsChannel::class),
            NotificationChannel::Push => app(PushChannel::class),
            NotificationChannel::InApp => app(InAppChannel::class),
        };
    }
}
