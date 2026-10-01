<?php

namespace Modules\Discount\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DiscountAlreadyAppliedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, __('discount::messages.already_applied'));
    }
}
