<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Discount\Models\Discount;
use Throwable;

class ApplicableOrderType
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
        $orderTypes = collect($discount->conditions['order_types'] ?? []);

        if ($orderTypes->isEmpty()) {
            return $next($discount);
        }
        $cart = resolve($cart);

        throw_if(
            !$cart::hasOrderType() || !$orderTypes->contains($cart::orderType()->value()),
            InapplicableDiscountException::class
        );

        return $next($discount);
    }
}
