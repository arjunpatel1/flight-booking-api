<?php

namespace Modules\Flight\Enums;

enum PaymentStatus: string
{
    case PENDING  = 'PENDING';
    case PAID     = 'PAID';
    case REFUNDED = 'REFUNDED';
    case FAILED   = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING  => 'Pending Payment',
            self::PAID     => 'Paid',
            self::REFUNDED => 'Refunded',
            self::FAILED   => 'Payment Failed',
        };
    }
}
