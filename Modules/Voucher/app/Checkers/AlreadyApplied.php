<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\VoucherAlreadyAppliedException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class AlreadyApplied
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws VoucherAlreadyAppliedException
     * @throws Throwable
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        throw_if(
            resolve($cart)::discountAlreadyApplied($voucher),
            VoucherAlreadyAppliedException::class
        );

        return $next($voucher);
    }
}
