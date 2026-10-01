<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ApplicableCustomer
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @param string $cart
     * @return mixed
     * @throws Throwable
     * @throws InapplicableDiscountException
     */
    public function handle(Voucher $voucher, Closure $next, string $cart): mixed
    {
        if (empty($voucher->meta['customer_id'])) {
            return $next($voucher);
        }

        throw_if(
            $voucher->meta['customer_id'] != resolve($cart)::customer()?->id(),
            InapplicableDiscountException::class
        );

        return $next($voucher);
    }
}
