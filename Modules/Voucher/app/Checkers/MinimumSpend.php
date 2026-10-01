<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\MinimumSpendException;
use Modules\Voucher\Models\Voucher;

class MinimumSpend
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws MinimumSpendException
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        $cart = resolve($cart);

        if ($voucher->didNotSpendTheRequiredAmount($cart)) {
            throw new MinimumSpendException(
                $voucher->minimum_spend->convert($cart::branch()?->currency())
            );
        }

        return $next($voucher);
    }
}
