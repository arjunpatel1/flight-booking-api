<?php

namespace Modules\Flight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightPayment;

class FlightPaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlightPayment $payment,
        public readonly ?FlightBooking $booking = null,
        public readonly string $reason = 'Payment failed'
    ) {
    }
}
