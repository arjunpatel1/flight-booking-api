<?php

namespace Modules\Flight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightRefund;

class FlightRefundInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlightRefund $refund,
        public readonly FlightBooking $booking
    ) {
    }
}
