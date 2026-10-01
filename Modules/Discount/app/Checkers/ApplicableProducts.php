<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Discount\Models\Discount;
use Throwable;

class ApplicableProducts
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
        $products = collect($discount->conditions['products'] ?? []);

        if ($products->isEmpty()) {
            return $next($discount);
        }

        $cartItems = resolve($cart)::items()->filter(fn($cartItem) => $products->contains($cartItem->product->sku));

        throw_if($cartItems->isEmpty(), InapplicableDiscountException::class);

        return $next($discount);
    }
}
