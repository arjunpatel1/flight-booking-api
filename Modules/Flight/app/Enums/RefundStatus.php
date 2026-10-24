<?php

namespace Modules\Flight\Enums;

enum RefundStatus: string
{
    case PENDING   = 'PENDING';
    case PROCESSED = 'PROCESSED';
    case FAILED    = 'FAILED';
    case REJECTED  = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Pending Processing',
            self::PROCESSED => 'Refund Processed',
            self::FAILED    => 'Refund Failed',
            self::REJECTED  => 'Refund Rejected',
        };
    }
}
