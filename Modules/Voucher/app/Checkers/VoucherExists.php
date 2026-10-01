<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\VoucherNotExistsException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class VoucherExists
{
    /**
     * @param Voucher|null $voucher
     * @param Closure $next
     * @return mixed
     * @throws VoucherNotExistsException
     * @throws Throwable
     */
    public function handle(?Voucher $voucher, Closure $next): mixed
    {
        throw_if(is_null($voucher), VoucherNotExistsException::class);

        return $next($voucher);
    }
}
