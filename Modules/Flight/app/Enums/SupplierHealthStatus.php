<?php

namespace Modules\Flight\Enums;

enum SupplierHealthStatus: string
{
    case ACTIVE   = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case DEGRADED = 'DEGRADED';
    case FAILED   = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE   => 'Operational / Healthy',
            self::INACTIVE => 'Disabled / Inactive',
            self::DEGRADED => 'Degraded Performance (High Latency / Partial Errors)',
            self::FAILED   => 'Outage / Connection Failed',
        };
    }

    public function isAvailable(): bool
    {
        return in_array($this, [self::ACTIVE, self::DEGRADED]);
    }
}
