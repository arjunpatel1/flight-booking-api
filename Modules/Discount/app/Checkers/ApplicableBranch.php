<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Discount\Models\Discount;
use Throwable;

class ApplicableBranch
{
    /**
     * @param Discount $discount
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     * @throws InapplicableDiscountException
     */
    public function handle(Discount $discount, Closure $next, string $cart): mixed
    {
        if (is_null($discount->branch_id)) {
            return $next($discount);
        }

        throw_if(
            $discount->branch_id != resolve($cart)::branch()?->id(),
            InapplicableDiscountException::class
        );

        return $next($discount);
    }
}
