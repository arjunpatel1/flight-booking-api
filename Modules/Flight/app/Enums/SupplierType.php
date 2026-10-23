<?php

namespace Modules\Flight\Enums;

enum SupplierType: string
{
    case GDS          = 'gds';
    case NDC          = 'ndc';
    case AIRLINE_API  = 'airline_api';
    case CONSOLIDATOR = 'consolidator';
    case MANUAL       = 'manual';
    case CONTRACTED   = 'contracted';

    public function label(): string
    {
        return match ($this) {
            self::GDS          => 'Global Distribution System (GDS)',
            self::NDC          => 'New Distribution Capability (NDC)',
            self::AIRLINE_API  => 'Direct Airline API',
            self::CONSOLIDATOR => 'Fare Consolidator',
            self::MANUAL       => 'Manual Offline Supplier',
            self::CONTRACTED   => 'Contracted Seat Inventory',
        };
    }
}
