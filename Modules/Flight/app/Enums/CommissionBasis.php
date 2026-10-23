<?php

namespace Modules\Flight\Enums;

enum CommissionBasis: string
{
    case BASE_FARE      = 'BASE_FARE';
    case TOTAL_FARE     = 'TOTAL_FARE';
    case PER_BOOKING    = 'PER_BOOKING';
    case PER_PASSENGER  = 'PER_PASSENGER';

    public function label(): string
    {
        return match ($this) {
            self::BASE_FARE     => 'Base Fare',
            self::TOTAL_FARE    => 'Total Fare',
            self::PER_BOOKING   => 'Per Booking',
            self::PER_PASSENGER => 'Per Passenger',
        };
    }
}
