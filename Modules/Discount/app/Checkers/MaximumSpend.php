<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\MaximumSpendException;
use Modules\Discount\Models\Discount;

class MaximumSpend
{
    /**
     * @param Discount $discount
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws MaximumSpendException
     */
    public function handle(Discount $discount, Closure $next, string $cart): mixed
    {
        $cart = resolve($cart);

        if ($discount->spentMoreThanMaximumAmount($cart)) {
            throw new MaximumSpendException(
                $discount->maximum_spend->convert($cart::branch()?->currency())
            );
        }

        return $next($discount);
    }
}
