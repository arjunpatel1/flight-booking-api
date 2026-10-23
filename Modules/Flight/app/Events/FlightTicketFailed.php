<?php

namespace Modules\Flight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightBooking;

class FlightTicketFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlightBooking $booking,
        public readonly string $reason = 'Ticketing failed'
    ) {
    }
}
