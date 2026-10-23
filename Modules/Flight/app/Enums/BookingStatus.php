<?php

namespace Modules\Flight\Enums;

enum BookingStatus: string
{
    case DRAFT           = 'DRAFT';
    case QUOTED          = 'QUOTED';
    case PAYMENT_PENDING = 'PAYMENT_PENDING';
    case PNR_PENDING     = 'PNR_PENDING';
    case BOOKED          = 'BOOKED';
    case CONFIRMED       = 'CONFIRMED';
    case CANCELLED       = 'CANCELLED';
    case FAILED          = 'FAILED';
    case COMPLETED       = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT           => 'Draft',
            self::QUOTED          => 'Quoted',
            self::PAYMENT_PENDING => 'Payment Pending',
            self::PNR_PENDING     => 'Payment Received · PNR Pending',
            self::BOOKED          => 'Booked',
            self::CONFIRMED       => 'Confirmed',
            self::CANCELLED       => 'Cancelled',
            self::FAILED          => 'Failed',
            self::COMPLETED       => 'Completed',
        };
    }

    /**
     * Map of valid transitions from the current state.
     * Prevents arbitrary status changes.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT           => [self::QUOTED, self::PAYMENT_PENDING, self::CANCELLED, self::FAILED],
            self::QUOTED          => [self::PAYMENT_PENDING, self::PNR_PENDING, self::CONFIRMED, self::CANCELLED, self::FAILED],
            self::PAYMENT_PENDING => [self::PNR_PENDING, self::CONFIRMED, self::CANCELLED, self::FAILED],
            self::PNR_PENDING     => [self::BOOKED, self::CANCELLED, self::FAILED],
            self::BOOKED          => [self::COMPLETED, self::CANCELLED],
            self::CONFIRMED       => [self::COMPLETED, self::CANCELLED],
            self::CANCELLED       => [],
            self::FAILED          => [],
            self::COMPLETED       => [self::CANCELLED],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::FAILED, self::COMPLETED], true);
    }
}
