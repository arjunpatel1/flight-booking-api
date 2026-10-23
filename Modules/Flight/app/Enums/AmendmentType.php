<?php

namespace Modules\Flight\Enums;

enum AmendmentType: string
{
    case RESCHEDULE   = 'RESCHEDULE';
    case CANCELLATION = 'CANCELLATION';
    case CORRECTION   = 'CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::RESCHEDULE   => 'Flight Reschedule',
            self::CANCELLATION => 'Booking Cancellation',
            self::CORRECTION   => 'Name / Details Correction',
        };
    }
}
