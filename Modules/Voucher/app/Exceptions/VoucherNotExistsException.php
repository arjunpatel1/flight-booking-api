<?php

namespace Modules\Voucher\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class VoucherNotExistsException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, __('voucher::messages.not_exists'));
    }
}
