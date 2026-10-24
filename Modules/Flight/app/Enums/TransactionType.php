<?php

namespace Modules\Flight\Enums;

enum TransactionType: string
{
    case INITIAL_ALLOCATION = 'INITIAL_ALLOCATION';
    case TENANT_TRANSFER    = 'TENANT_TRANSFER';
    case CHANNEL_ALLOCATION = 'CHANNEL_ALLOCATION';
    case CHANNEL_RETURN     = 'CHANNEL_RETURN';
    case BOOKING            = 'BOOKING';
    case RELEASE            = 'RELEASE';
    case CANCELLATION       = 'CANCELLATION';
    case REFUND             = 'REFUND';
    case EXPIRY             = 'EXPIRY';
    case FREEZE             = 'FREEZE';
    case UNFREEZE           = 'UNFREEZE';
    case ADJUSTMENT         = 'ADJUSTMENT';
    case RECONCILIATION     = 'RECONCILIATION';

    public function label(): string
    {
        return match ($this) {
            self::INITIAL_ALLOCATION => 'Initial Allocation',
            self::TENANT_TRANSFER    => 'Tenant Transfer',
            self::CHANNEL_ALLOCATION => 'Channel Allocation',
            self::CHANNEL_RETURN     => 'Channel Return',
            self::BOOKING            => 'Booking',
            self::RELEASE            => 'Release',
            self::CANCELLATION       => 'Cancellation',
            self::REFUND             => 'Refund',
            self::EXPIRY             => 'Expiry',
            self::FREEZE             => 'Freeze',
            self::UNFREEZE           => 'Unfreeze',
            self::ADJUSTMENT         => 'Adjustment',
            self::RECONCILIATION     => 'Reconciliation',
        };
    }

    /** Whether this transaction type adds seats (+) or removes (-) from the destination. */
    public function direction(): int
    {
        return match ($this) {
            self::INITIAL_ALLOCATION,
            self::CHANNEL_ALLOCATION,
            self::RELEASE,
            self::REFUND,
            self::CANCELLATION,
            self::UNFREEZE => 1,
            self::TENANT_TRANSFER,
            self::CHANNEL_RETURN,
            self::BOOKING,
            self::EXPIRY,
            self::FREEZE => -1,
            self::ADJUSTMENT,
            self::RECONCILIATION => 0, // can be either; caller sets delta sign
        };
    }
}
