<?php

namespace Modules\Voucher\Exceptions;

use Modules\Support\Money;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MinimumSpendException extends HttpException
{
    public function __construct(Money $minimumSpend)
    {
        parent::__construct(
            403,
            __('voucher::messages.minimum_spend', ['amount' => $minimumSpend->format()])
        );
    }
}
