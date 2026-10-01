<?php

namespace Modules\Discount\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DiscountUsageLimitReachedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, __('discount::messages.usage_limit_reached'));
    }
}
