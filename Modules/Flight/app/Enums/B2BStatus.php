<?php

namespace Modules\Flight\Enums;

enum B2BStatus: string
{
    case ACTIVE    = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case INACTIVE  = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE    => 'Active',
            self::SUSPENDED => 'Suspended',
            self::INACTIVE  => 'Inactive',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }
}
