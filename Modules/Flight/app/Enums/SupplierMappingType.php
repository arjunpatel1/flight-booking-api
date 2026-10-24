<?php

namespace Modules\Flight\Enums;

enum SupplierMappingType: string
{
    case AIRLINE   = 'airline';
    case FLIGHT    = 'flight';
    case FARE      = 'fare';
    case INVENTORY = 'inventory';
    case REFERENCE = 'reference';
    case PNR       = 'pnr';
    case TICKET    = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::AIRLINE   => 'Airline Carrier Code',
            self::FLIGHT    => 'Flight Number / Segment',
            self::FARE      => 'Fare Basis / Class Code',
            self::INVENTORY => 'Inventory / Cabin Mapping',
            self::REFERENCE => 'Supplier Booking / Quote Reference',
            self::PNR       => 'Supplier Record Locator (PNR)',
            self::TICKET    => 'Supplier E-Ticket Number',
        };
    }
}
