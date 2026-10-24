<?php

namespace Modules\Flight\Enums;

enum ReconciliationStatus: string
{
    case PENDING           = 'PENDING';
    case MATCHED           = 'MATCHED';
    case MISMATCH_DETECTED = 'MISMATCH_DETECTED';
    case RECONCILED        = 'RECONCILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING           => 'Pending Reconciliation',
            self::MATCHED           => 'Fully Matched',
            self::MISMATCH_DETECTED => 'Discrepancy Detected',
            self::RECONCILED        => 'Reconciled',
        };
    }
}
