<?php

namespace Modules\Flight\Services\Notification;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\FlightNotificationChannel;
use Modules\Flight\Enums\FlightNotificationEvent;
use Modules\Flight\Enums\FlightNotificationStatus;
use Modules\Flight\Jobs\SendFlightNotificationJob;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightNotificationDelivery;
use Throwable;

class FlightNotificationService
{
    /**
     * Dispatch event-driven notifications across specified channels.
     * Guaranteed to never throw unhandled exceptions or fail calling transaction.
     *
     * @param FlightNotificationEvent $event
     * @param array $recipients ['email' => 'user@example.com', 'phone' => '+919999999999']
     * @param string $template
     * @param array $data Dynamic template payload
     * @param FlightBooking|null $booking
     * @param int|null $tenantId
     * @param array $channels Array of channel strings or FlightNotificationChannel
     * @return Collection<int, FlightNotificationDelivery>
     */
    public function dispatchNotification(
        FlightNotificationEvent $event,
        array $recipients,
        string $template,
        array $data,
        ?FlightBooking $booking = null,
        ?int $tenantId = null,
        array $channels = ['email']
    ): Collection {
        $createdDeliveries = new Collection();
        $tenantId = $tenantId ?? $booking?->tenant_id;

        try {
            foreach ($channels as $chan) {
                $channelEnum = $chan instanceof FlightNotificationChannel
                    ? $chan
                    : FlightNotificationChannel::tryFrom(strtolower((string) $chan));

                if (! $channelEnum) {
                    continue;
                }

                // Determine recipient for this channel
                $recipient = match ($channelEnum) {
                    FlightNotificationChannel::EMAIL    => $recipients['email'] ?? ($booking?->contact_email ?? null),
                    FlightNotificationChannel::SMS,
                    FlightNotificationChannel::WHATSAPP => $recipients['phone'] ?? $recipients['sms'] ?? $recipients['whatsapp'] ?? ($booking?->contact_phone ?? null),
                };

                if (empty($recipient)) {
                    continue;
                }

                // Generate deterministic idempotency key for this event + booking + channel + recipient
                $bookingKey = $booking ? "booking:{$booking->id}" : 'direct';
                $idempotencyKey = hash('sha256', "{$tenantId}:{$bookingKey}:{$event->value}:{$channelEnum->value}:{$recipient}:{$template}");

                // Check for existing delivery
                $existing = FlightNotificationDelivery::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if (in_array($existing->status, [FlightNotificationStatus::SENT, FlightNotificationStatus::DELIVERED], true)) {
                        $createdDeliveries->push($existing);
                        continue;
                    }
                }

                $delivery = FlightNotificationDelivery::create([
                    'booking_id'      => $booking?->id,
                    'tenant_id'       => $tenantId,
                    'event'           => $event,
                    'channel'         => $channelEnum,
                    'recipient'       => $recipient,
                    'template'        => $template,
                    'status'          => FlightNotificationStatus::QUEUED,
                    'provider'        => null,
                    'attempt_count'   => 0,
                    'metadata'        => $data,
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Dispatch Queue Job
                SendFlightNotificationJob::dispatch($delivery->id, $data);

                $createdDeliveries->push($delivery);
            }
        } catch (Throwable $e) {
            // CRITICAL: A failed notification MUST NOT fail an already successful booking/payment/ticket transaction.
            Log::error('FlightNotificationService: notification dispatch error', [
                'event' => $event->value,
                'error' => $e->getMessage(),
            ]);
        }

        return $createdDeliveries;
    }

    /**
     * Synchronously execute delivery for immediate completion/testing.
     */
    public function sendSync(FlightNotificationDelivery $delivery, array $data = []): FlightNotificationDelivery
    {
        $job = new SendFlightNotificationJob($delivery->id, $data);
        $job->handle();

        return $delivery->fresh();
    }

    /**
     * Retry a failed or pending notification delivery.
     */
    public function retry(FlightNotificationDelivery $delivery): FlightNotificationDelivery
    {
        $delivery->update([
            'status'         => FlightNotificationStatus::RETRYING,
            'failure_reason' => null,
        ]);

        SendFlightNotificationJob::dispatch($delivery->id, $delivery->metadata ?? []);

        return $delivery->fresh();
    }

    /**
     * Get all notification delivery logs for a given booking.
     */
    public function getDeliveriesForBooking(int|string $bookingIdentifier, ?int $tenantId = null): Collection
    {
        $booking = FlightBooking::where('id', is_numeric($bookingIdentifier) ? (int) $bookingIdentifier : 0)
            ->orWhere('booking_reference', $bookingIdentifier)
            ->first();

        if (! $booking) {
            return new Collection();
        }

        return FlightNotificationDelivery::where('booking_id', $booking->id)
            ->forTenant($tenantId)
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * List and filter notification delivery records.
     */
    public function listDeliveries(array $filters = [], ?int $tenantId = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FlightNotificationDelivery::query()
            ->with('booking')
            ->forTenant($tenantId);

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['recipient'])) {
            $query->where('recipient', 'like', '%' . $filters['recipient'] . '%');
        }

        if (! empty($filters['booking_reference'])) {
            $query->whereHas('booking', function ($q) use ($filters) {
                $q->where('booking_reference', $filters['booking_reference']);
            });
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
}
