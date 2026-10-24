<?php

namespace Modules\Flight\Enums;

enum AllocationStatus: string
{
    case REQUESTED  = 'REQUESTED';
    case APPROVED   = 'APPROVED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED  = 'COMPLETED';
    case REJECTED   = 'REJECTED';
    case CANCELLED  = 'CANCELLED';
    case REVERSED   = 'REVERSED';

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED  => 'Requested',
            self::APPROVED   => 'Approved',
            self::PROCESSING => 'Processing',
            self::COMPLETED  => 'Completed',
            self::REJECTED   => 'Rejected',
            self::CANCELLED  => 'Cancelled',
            self::REVERSED   => 'Reversed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::REJECTED,
            self::CANCELLED,
            self::REVERSED,
        ]);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::REQUESTED  => in_array($next, [self::APPROVED, self::REJECTED, self::CANCELLED]),
            self::APPROVED   => in_array($next, [self::PROCESSING, self::CANCELLED]),
            self::PROCESSING => in_array($next, [self::COMPLETED, self::REJECTED]),
            self::COMPLETED  => $next === self::REVERSED,
            default          => false,
        };
    }
}
