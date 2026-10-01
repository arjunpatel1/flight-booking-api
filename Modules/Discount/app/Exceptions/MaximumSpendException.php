<?php

namespace Modules\Discount\Exceptions;

use Modules\Support\Money;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaximumSpendException extends HttpException
{
    public function __construct(Money $maximumSpend)
    {
        parent::__construct(
            403,
            __('discount::messages.maximum_spend', ['amount' => $maximumSpend->format()])
        );
    }
}
