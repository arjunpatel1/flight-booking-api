<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\InapplicableDiscountException;
use Modules\Discount\Models\Discount;
use Throwable;

class ApplicableDay
{
    /**
     * @param Discount $discount
     * @param Closure $next
     * @return mixed
     * @throws Throwable
     * @throws InapplicableDiscountException
     */
    public function handle(Discount $discount, Closure $next): mixed
    {
        $availableDays = collect($discount->conditions['available_days'] ?? []);

        if ($availableDays->isEmpty()) {
            return $next($discount);
        }

        throw_unless(
            $availableDays->contains(strtolower(today()->englishDayOfWeek)),
            InapplicableDiscountException::class
        );

        return $next($discount);
    }
}
