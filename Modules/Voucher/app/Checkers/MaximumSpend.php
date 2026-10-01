<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\MaximumSpendException;
use Modules\Voucher\Models\Voucher;

class MaximumSpend
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws MaximumSpendException
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        $cart = resolve($cart);

        if ($voucher->spentMoreThanMaximumAmount($cart)) {
            throw new MaximumSpendException(
                $voucher->maximum_spend->convert($cart::branch()?->currency())
            );
        }

        return $next($voucher);
    }
}
