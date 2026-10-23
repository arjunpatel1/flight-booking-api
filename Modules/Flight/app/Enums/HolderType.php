<?php

namespace Modules\Flight\Enums;

enum HolderType: string
{
    case CENTRAL     = 'central';
    case TENANT      = 'tenant';
    case DISTRIBUTOR = 'distributor';
    case AGENT       = 'agent';
    case B2C         = 'b2c';
    case SYSTEM      = 'system';

    public function label(): string
    {
        return match ($this) {
            self::CENTRAL     => 'Central Pool',
            self::TENANT      => 'Tenant',
            self::DISTRIBUTOR => 'Distributor',
            self::AGENT       => 'Agent',
            self::B2C         => 'B2C',
            self::SYSTEM      => 'System',
        };
    }

    /** Returns holder types that can receive from this holder. */
    public function allowedChildren(): array
    {
        return match ($this) {
            self::CENTRAL     => [self::TENANT],
            self::TENANT      => [self::DISTRIBUTOR, self::AGENT, self::B2C],
            self::DISTRIBUTOR => [self::AGENT, self::B2C],
            self::AGENT       => [self::B2C],
            self::B2C         => [],
            self::SYSTEM      => [],
        };
    }

    /** Can this holder type return to the given parent? */
    public function canReturnTo(self $parent): bool
    {
        return in_array($this, $parent->allowedChildren());
    }

    public function hierarchyLevel(): int
    {
        return match ($this) {
            self::CENTRAL     => 0,
            self::TENANT      => 1,
            self::DISTRIBUTOR => 2,
            self::AGENT       => 3,
            self::B2C         => 4,
            self::SYSTEM      => -1,
        };
    }
}
