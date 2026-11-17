<?php

namespace Modules\Flight\Exceptions;

use Exception;

/**
 * The wallet balance does not cover the amount. B2B wallets are prepaid (no
 * credit), so the only fix is adding money to the wallet.
 * `availableCredit` / `creditLimit` are kept for callers; creditLimit is always 0.
 */
class InsufficientBalanceException extends Exception
{
    public function __construct(
        public readonly float $requestedAmount,
        public readonly float $availableCredit,
        public readonly float $balance,
        public readonly float $creditLimit = 0.0,
        string $message = 'Not enough money in the wallet. Add money to the wallet and try again.'
    ) {
        parent::__construct($message . sprintf(' Required: %.2f, wallet balance: %.2f.', $requestedAmount, $balance), 422);
    }
}
