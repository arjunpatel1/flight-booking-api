<?php

namespace Modules\Discount\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DiscountNotExistsException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, __('discount::messages.not_exists'));
    }
}
