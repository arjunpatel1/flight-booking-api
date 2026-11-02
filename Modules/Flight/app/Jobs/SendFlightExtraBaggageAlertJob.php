<?php

namespace Modules\Flight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Listeners\FlightNotificationSubscriber;
use Modules\Flight\Models\FlightBooking;

/**
 * Tells the business owner (WhatsApp and email) that a customer asked for
 * extra baggage, so the agent can quote and collect the charge offline.
 * Runs on the queue so the customer's booking request never waits on the
 * WhatsApp provider.
 */
class SendFlightExtraBaggageAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One attempt: a retry after a partial send would message the owner twice.
    public int $tries = 1;

    public function __construct(public readonly int $bookingId)
    {
        $this->onQueue('notifications');
    }

    public function handle(FlightNotificationSubscriber $subscriber): void
    {
        $booking = FlightBooking::query()
            ->with(['ancillaries.passenger', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->find($this->bookingId);

        if ($booking) {
            $subscriber->notifyOwnerOfExtraBaggage($booking);
        }
    }
}
