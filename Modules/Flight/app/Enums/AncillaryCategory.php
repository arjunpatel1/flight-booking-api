<?php

namespace Modules\Flight\Enums;

enum AncillaryCategory: string
{
    case SEAT               = 'SEAT';
    case BAGGAGE            = 'BAGGAGE';
    case MEAL               = 'MEAL';
    case LOUNGE             = 'LOUNGE';
    case SPECIAL_ASSISTANCE = 'SPECIAL_ASSISTANCE';
    case PRIORITY_BOARDING  = 'PRIORITY_BOARDING';

    public function label(): string
    {
        return match ($this) {
            self::SEAT               => 'Seat Selection',
            self::BAGGAGE            => 'Extra Baggage',
            self::MEAL               => 'In-Flight Meal',
            self::LOUNGE             => 'Airport Lounge',
            self::SPECIAL_ASSISTANCE => 'Special Assistance',
            self::PRIORITY_BOARDING  => 'Priority Boarding',
        };
    }
}
