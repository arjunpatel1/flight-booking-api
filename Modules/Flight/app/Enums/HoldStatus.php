<?php

namespace Modules\Flight\Enums;

enum HoldStatus: string
{
    case ACTIVE    = 'ACTIVE';
    case RELEASED  = 'RELEASED';
    case EXPIRED   = 'EXPIRED';
    case CONVERTED = 'CONVERTED';
    case FAILED    = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE    => 'Active Hold',
            self::RELEASED  => 'Released',
            self::EXPIRED   => 'Expired',
            self::CONVERTED => 'Converted to Booking',
            self::FAILED    => 'Failed',
        };
    }

    public function isHoldable(): bool
    {
        return $this === self::ACTIVE;
    }
}
