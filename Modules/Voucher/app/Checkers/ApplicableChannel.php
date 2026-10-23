<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Voucher\Models\Voucher;

class ApplicableChannel
{
    public function handle(Voucher $voucher, Closure $next, string $channel): mixed
    {
        $channels = collect(data_get($voucher->conditions, 'channels', []));
        if ($channels->isEmpty() || $channels->contains($channel)) return $next($voucher);
        throw new InapplicableDiscountException();
    }
}
