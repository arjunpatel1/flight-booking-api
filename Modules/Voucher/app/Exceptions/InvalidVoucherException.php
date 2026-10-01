<?php

namespace Modules\Voucher\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidVoucherException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, __('voucher::messages.invalid_voucher'));
    }
}
