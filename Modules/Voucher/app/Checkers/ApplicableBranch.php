<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ApplicableBranch
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     * @throws InapplicableDiscountException
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        if (is_null($voucher->branch_id)) {
            return $next($voucher);
        }

        throw_if(
            $voucher->branch_id != resolve($cart)::branch()?->id(),
            InapplicableDiscountException::class
        );

        return $next($voucher);
    }
}
