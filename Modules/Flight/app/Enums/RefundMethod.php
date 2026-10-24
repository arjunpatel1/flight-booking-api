<?php

namespace Modules\Flight\Enums;

enum RefundMethod: string
{
    case WALLET           = 'WALLET';
    case GATEWAY          = 'GATEWAY';
    case ORIGINAL_PAYMENT = 'ORIGINAL_PAYMENT';

    public function label(): string
    {
        return match ($this) {
            self::WALLET           => 'B2B Agency Wallet',
            self::GATEWAY          => 'Payment Gateway',
            self::ORIGINAL_PAYMENT => 'Original Payment Source',
        };
    }
}
