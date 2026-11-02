<?php

namespace Modules\Flight\Models;

/** A flight offer the customer enters by code at payment. */
class FlightCoupon extends FlightOffer
{
    protected $table = 'flight_coupons';

    public function kind(): string
    {
        return 'coupon';
    }
}
