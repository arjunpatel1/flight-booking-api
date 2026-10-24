<?php

namespace Modules\Flight\Enums;

use DomainException;

enum FlightPaymentStatus: string
{
    case PENDING    = 'PENDING';
    case INITIATED  = 'INITIATED';
    case AUTHORIZED = 'AUTHORIZED';
    case COMPLETED  = 'COMPLETED';
    case FAILED     = 'FAILED';
    case CANCELLED  = 'CANCELLED';
    case REFUNDED   = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Pending',
            self::INITIATED  => 'Payment Initiated',
            self::AUTHORIZED => 'Authorized',
            self::COMPLETED  => 'Completed',
            self::FAILED     => 'Failed',
            self::CANCELLED  => 'Cancelled',
            self::REFUNDED   => 'Refunded',
        };
    }

    public function canTransitionTo(FlightPaymentStatus $target): bool
    {
        if ($this === $target) {
            return true; // idempotent
        }

        $validTransitions = [
            self::PENDING->value => [
                self::INITIATED->value,
                self::CANCELLED->value,
                self::FAILED->value,
            ],
            self::INITIATED->value => [
                self::AUTHORIZED->value,
                self::COMPLETED->value,
                self::FAILED->value,
                self::CANCELLED->value,
            ],
            self::AUTHORIZED->value => [
                self::COMPLETED->value,
                self::FAILED->value,
            ],
            self::COMPLETED->value => [
                self::REFUNDED->value,
            ],
            self::FAILED->value => [],
            self::CANCELLED->value => [],
            self::REFUNDED->value => [],
        ];

        return in_array($target->value, $validTransitions[$this->value] ?? [], true);
    }

    public function assertCanTransitionTo(FlightPaymentStatus $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new DomainException(
                "Invalid flight payment state transition from [{$this->value}] to [{$target->value}]."
            );
        }
    }
}
