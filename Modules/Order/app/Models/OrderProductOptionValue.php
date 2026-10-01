<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Modules\Option\Models\OptionValue;
use Modules\Support\Eloquent\Pivot;
use Modules\Support\Money;

/**
 * @property Money $price
 * @property string $currency
 * @property float $currency_rate
 * @mixin OptionValue
 */
class OrderProductOptionValue extends Pivot
{
    /**
     * Get price
     *
     * @return Attribute
     */
    public function price(): Attribute
    {
        return Attribute::get(fn($amount) => !is_null($amount) ? new Money($amount, $this->currency) : null);
    }
}
