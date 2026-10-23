<?php

namespace Modules\Flight\Enums;

enum FlightPaymentMethod: string
{
    case GATEWAY = 'GATEWAY';
    case WALLET  = 'WALLET';
    case UPI     = 'UPI';
    case CARD    = 'CARD';

    public function label(): string
    {
        return match ($this) {
            self::GATEWAY => 'Online Payment Gateway',
            self::WALLET  => 'B2B Agent / Tenant Wallet',
            self::UPI     => 'Direct UPI',
            self::CARD    => 'Credit / Debit Card',
        };
    }
}
