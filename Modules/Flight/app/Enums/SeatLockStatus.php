<?php

namespace Modules\Flight\Enums;

enum SeatLockStatus: string
{
    case LOCKED    = 'LOCKED';
    case CONFIRMED = 'CONFIRMED';
    case RELEASED  = 'RELEASED';
    case EXPIRED   = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::LOCKED    => 'Temporarily Locked',
            self::CONFIRMED => 'Confirmed Selection',
            self::RELEASED  => 'Released',
            self::EXPIRED   => 'Expired',
        };
    }

    public function isHeld(): bool
    {
        return in_array($this, [self::LOCKED, self::CONFIRMED], true);
    }
}
