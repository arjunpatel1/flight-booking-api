<?php

namespace Modules\Flight\Enums;

enum AmendmentStatus: string
{
    case QUOTED    = 'QUOTED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::QUOTED    => 'Quoted',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
