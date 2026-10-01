<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Discount\Models\Discount;
use Throwable;

class ApplicableCategories
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
        $categories = collect($discount->conditions['categories'] ?? []);

        if ($categories->isEmpty()) {
            return $next($discount);
        }

        $products = collect($discount->conditions['products'] ?? []);

        $cartItems = resolve($cart)::items()
            ->when(
                $products->isNotEmpty(),
                fn($collect) => $collect->filter(fn($cartItem) => $products->contains($cartItem->product->sku))
            )
            ->filter(
                fn($cartItem) => $categories
                    ->intersect($cartItem->product->categories->pluck('slug'))
                    ->isNotEmpty()
            );

        throw_if($cartItems->isEmpty(), InapplicableDiscountException::class);

        return $next($discount);
    }
}
