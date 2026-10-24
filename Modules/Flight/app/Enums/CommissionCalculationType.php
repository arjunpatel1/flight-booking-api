<?php

namespace Modules\Flight\Enums;

enum CommissionCalculationType: string
{
    case PERCENTAGE = 'PERCENTAGE';
    case FIXED      = 'FIXED';
    case HYBRID     = 'HYBRID';

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE => 'Percentage',
            self::FIXED      => 'Fixed Amount',
            self::HYBRID     => 'Hybrid (Percentage + Fixed)',
        };
    }
}
