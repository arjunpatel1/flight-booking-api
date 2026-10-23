<?php

namespace Modules\Flight\Enums;

enum SettlementBatchStatus: string
{
    case PENDING    = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case PROCESSED  = 'PROCESSED';
    case FAILED     = 'FAILED';
    case RECONCILED = 'RECONCILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Pending',
            self::PROCESSING => 'Processing',
            self::PROCESSED  => 'Processed',
            self::FAILED     => 'Failed',
            self::RECONCILED => 'Reconciled',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING    => in_array($target, [self::PROCESSING, self::FAILED]),
            self::PROCESSING => in_array($target, [self::PROCESSED, self::FAILED]),
            self::PROCESSED  => in_array($target, [self::RECONCILED, self::PROCESSING]), // can re-process or reconcile
            self::FAILED     => in_array($target, [self::PROCESSING, self::PENDING]),    // retry
            self::RECONCILED => false,
        };
    }
}
