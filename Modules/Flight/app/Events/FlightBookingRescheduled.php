<?php

namespace Modules\Flight\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightBookingAmendment;

class FlightBookingRescheduled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FlightBooking $booking,
        public FlightBookingAmendment $amendment
    ) {}
}
