<?php

namespace Modules\Voucher\Checkers;

use Closure;
use Modules\Voucher\Exceptions\InapplicableVoucherException;
use Modules\Voucher\Models\Voucher;
use Throwable;

class ApplicableDay
{
    /**
     * @param Voucher $voucher
     * @param Closure $next
     * @return mixed
     * @throws Throwable
     * @throws InapplicableVoucherException
     */
    public function handle(Voucher $voucher, Closure $next): mixed
    {
        $availableDays = collect($voucher->conditions['available_days'] ?? []);

        if ($availableDays->isEmpty()) {
            return $next($voucher);
        }

        throw_unless(
            $availableDays->contains(strtolower(today()->englishDayOfWeek)),
            InapplicableVoucherException::class
        );

        return $next($voucher);
    }
}
