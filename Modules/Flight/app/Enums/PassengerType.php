<?php

namespace Modules\Flight\Enums;

enum PassengerType: string
{
    case ADULT  = 'ADULT';
    case CHILD  = 'CHILD';
    case INFANT = 'INFANT';

    public function label(): string
    {
        return match ($this) {
            self::ADULT  => 'Adult (12+ yrs)',
            self::CHILD  => 'Child (2-11 yrs)',
            self::INFANT => 'Infant (< 2 yrs)',
        };
    }
}
