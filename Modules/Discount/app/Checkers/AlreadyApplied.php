<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\DiscountAlreadyAppliedException;
use Modules\Discount\Models\Discount;
use Throwable;

class AlreadyApplied
{
    /**
     * @param Discount $discount
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws DiscountAlreadyAppliedException
     * @throws Throwable
     */
    public function handle(Discount $discount, Closure $next, string $cart): mixed
    {
        throw_if(
            resolve($cart)::discountAlreadyApplied($discount),
            DiscountAlreadyAppliedException::class
        );

        return $next($discount);
    }
}
