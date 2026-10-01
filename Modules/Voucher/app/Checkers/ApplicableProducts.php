<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\InapplicableVoucherException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ApplicableProducts
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
        $products = collect($voucher->conditions['products'] ?? []);

        if ($products->isEmpty()) {
            return $next($voucher);
        }

        $cartItems = resolve($cart)::items()->filter(fn($cartItem) => $products->contains($cartItem->product->sku));

        throw_if($cartItems->isEmpty(), InapplicableVoucherException::class);

        return $next($voucher);
    }
}
