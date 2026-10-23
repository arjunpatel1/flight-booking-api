<?php

namespace Modules\Flight\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    public function __construct(
        public readonly float $requestedAmount,
        public readonly float $availableCredit,
        public readonly float $balance,
        public readonly float $creditLimit,
        string $message = 'Insufficient purchasing power or credit limit for this transaction.'
    ) {
        parent::__construct($message . sprintf(' Required: %.2f, Available: %.2f (Balance: %.2f, Credit Limit: %.2f)', $requestedAmount, $availableCredit, $balance, $creditLimit), 422);
    }
}
