<?php

namespace Modules\Flight\Enums;

enum WalletTransactionType: string
{
    case RECHARGE = 'RECHARGE';
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';
    case BOOKING_DEDUCTION = 'BOOKING_DEDUCTION';
    case REFUND = 'REFUND';
    case COMMISSION = 'COMMISSION';
    case ADJUSTMENT = 'ADJUSTMENT';
    case TRANSFER = 'TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::RECHARGE => 'Wallet Recharge',
            self::DEBIT => 'Wallet Debit',
            self::CREDIT => 'Wallet Credit',
            self::BOOKING_DEDUCTION => 'Booking Deduction',
            self::REFUND => 'Refund Credit',
            self::COMMISSION => 'Commission Credit',
            self::ADJUSTMENT => 'Manual Adjustment',
            self::TRANSFER => 'Wallet Transfer',
        };
    }
}
