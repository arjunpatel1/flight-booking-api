<?php

namespace Modules\Flight\Models;

/** A flight offer shown automatically at payment for eligible bookings (no code needed). */
class FlightDiscount extends FlightOffer
{
    protected $table = 'flight_discounts';

    public function kind(): string
    {
        return 'discount';
    }
}
