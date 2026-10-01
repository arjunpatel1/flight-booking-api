<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\DiscountUsageLimitReachedException;
use Modules\Discount\Models\Discount;
use Throwable;

class UsageLimitPerCustomer
{
    /**
     * @param Discount $discount
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     */
    public function handle(Discount $discount, Closure $next, string $cart): mixed
    {
        $cart = resolve($cart);
        throw_if(
            $discount->perCustomerUsageLimitReached(
                $cart::customer()?->id(),
                $cart::currentOrder()?->id()
            ),
            DiscountUsageLimitReachedException::class
        );

        return $next($discount);
    }
}
