<?php

namespace Modules\Flight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\FlightNotificationChannel;
use Modules\Flight\Enums\FlightNotificationStatus;
use Modules\Flight\Models\FlightNotificationDelivery;
use Modules\Flight\Services\Notification\Channels\FlightEmailChannel;
use Modules\Flight\Services\Notification\Channels\FlightNotificationChannelInterface;
use Modules\Flight\Services\Notification\Channels\FlightSmsChannel;
use Modules\Flight\Services\Notification\Channels\FlightWhatsAppChannel;
use Throwable;

class SendFlightNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 180];

    public function __construct(
        public readonly int $deliveryId,
        public readonly array $data = []
    ) {}

    public function handle(): void
    {
        $delivery = FlightNotificationDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        // Idempotency check: already delivered or sent
        if (in_array($delivery->status, [FlightNotificationStatus::SENT, FlightNotificationStatus::DELIVERED], true)) {
            return;
        }

        $delivery->increment('attempt_count');

        $channelInstance = $this->resolveChannel($delivery->channel);
        if (! $channelInstance) {
            $delivery->update([
                'status'         => FlightNotificationStatus::FAILED,
                'failed_at'      => now(),
                'failure_reason' => "Unsupported notification channel: {$delivery->channel->value}",
            ]);
            return;
        }

        try {
            $delivery->loadMissing('booking');
            $result = $channelInstance->send(
                recipient: $delivery->recipient,
                template:  $delivery->template,
                data:      array_merge($delivery->metadata ?? [], $this->data),
                booking:   $delivery->booking
            );

            if (! empty($result['success'])) {
                $delivery->update([
                    'status'             => FlightNotificationStatus::SENT,
                    'provider'           => $result['provider'] ?? $delivery->provider,
                    'provider_reference' => $result['provider_reference'] ?? $delivery->provider_reference,
                    'sent_at'            => now(),
                    'delivered_at'       => ($result['status'] ?? '') === 'DELIVERED' ? now() : null,
                    'failure_reason'     => null,
                    'metadata'           => array_merge($delivery->metadata ?? [], $result['metadata'] ?? []),
                ]);
            } else {
                $isLastAttempt = $delivery->attempt_count >= $this->tries;
                $delivery->update([
                    'status'             => $isLastAttempt ? FlightNotificationStatus::FAILED : FlightNotificationStatus::RETRYING,
                    'failed_at'          => now(),
                    'failure_reason'     => $result['error'] ?? 'Unknown delivery failure',
                    'provider'           => $result['provider'] ?? $delivery->provider,
                    'provider_reference' => $result['provider_reference'] ?? $delivery->provider_reference,
                    'metadata'           => array_merge($delivery->metadata ?? [], $result['metadata'] ?? []),
                ]);

                if (! $isLastAttempt) {
                    $this->release($this->backoff[$delivery->attempt_count - 1] ?? 60);
                }
            }
        } catch (Throwable $e) {
            Log::warning("SendFlightNotificationJob exception for delivery {$delivery->id}", [
                'error' => $e->getMessage(),
            ]);

            $isLastAttempt = $delivery->attempt_count >= $this->tries;
            $delivery->update([
                'status'         => $isLastAttempt ? FlightNotificationStatus::FAILED : FlightNotificationStatus::RETRYING,
                'failed_at'      => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            if (! $isLastAttempt) {
                $this->release($this->backoff[$delivery->attempt_count - 1] ?? 60);
            }
        }
    }

    protected function resolveChannel(FlightNotificationChannel $channel): ?FlightNotificationChannelInterface
    {
        return match ($channel) {
            FlightNotificationChannel::EMAIL    => app(FlightEmailChannel::class),
            FlightNotificationChannel::SMS      => app(FlightSmsChannel::class),
            FlightNotificationChannel::WHATSAPP => app(FlightWhatsAppChannel::class),
        };
    }
}
