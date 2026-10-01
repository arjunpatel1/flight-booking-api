<?php

namespace Modules\Discount\Checkers;

use Closure;
use Modules\Discount\Exceptions\DiscountNotExistsException;
use Modules\Discount\Models\Discount;
use Throwable;

class DiscountExists
{
    /**
     * @param Discount|null $discount
     * @param Closure $next
     * @return mixed
     * @throws DiscountNotExistsException
     * @throws Throwable
     */
    public function handle(?Discount $discount, Closure $next): mixed
    {
        throw_if(is_null($discount), DiscountNotExistsException::class);

        return $next($discount);
    }
}
