<?php

namespace Modules\Flight\Enums;

enum CommissionRecipientTier: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case TENANT      = 'TENANT';
    case DISTRIBUTOR = 'DISTRIBUTOR';
    case AGENT       = 'AGENT';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::TENANT      => 'Tenant',
            self::DISTRIBUTOR => 'Distributor',
            self::AGENT       => 'Agent',
        };
    }

    public function hierarchyLevel(): int
    {
        return match ($this) {
            self::SUPER_ADMIN => 0,
            self::TENANT      => 1,
            self::DISTRIBUTOR => 2,
            self::AGENT       => 3,
        };
    }
}
