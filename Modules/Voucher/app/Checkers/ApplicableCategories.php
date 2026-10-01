<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\InapplicableVoucherException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ApplicableCategories
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     * @throws InapplicableVoucherException
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        $categories = collect($voucher->conditions['categories'] ?? []);

        if ($categories->isEmpty()) {
            return $next($voucher);
        }

        $products = collect($voucher->conditions['products'] ?? []);

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

        throw_if($cartItems->isEmpty(), InapplicableVoucherException::class);

        return $next($voucher);
    }
}
