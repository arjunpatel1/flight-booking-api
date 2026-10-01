<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\InvalidVoucherException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ValidVoucher
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @return mixed
     * @throws InvalidVoucherException
     * @throws Throwable
     */
    public function handle(Voucher $voucher, Closure $next): mixed
    {
        throw_if($voucher->invalid(), InvalidVoucherException::class);

        return $next($voucher);
    }
}
