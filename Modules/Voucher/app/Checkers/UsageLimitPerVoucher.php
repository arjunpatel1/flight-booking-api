<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\VoucherUsageLimitReachedException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class UsageLimitPerVoucher
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        $cart = resolve($cart);
        throw_if(
            $voucher->usageLimitReached(
                $cart::customer()?->id(),
                $cart::currentOrder()?->id()
            ),
            VoucherUsageLimitReachedException::class
        );

        return $next($voucher);
    }
}
