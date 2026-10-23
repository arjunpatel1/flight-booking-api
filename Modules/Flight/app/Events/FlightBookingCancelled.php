<?php

namespace Modules\Flight\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightRefund;

class FlightBookingCancelled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FlightBooking $booking,
        public FlightRefund $refund
    ) {}
}
