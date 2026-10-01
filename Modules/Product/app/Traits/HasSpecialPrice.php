<?php

namespace Modules\Product\Traits;

use Carbon\Carbon;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;

/**
 * Trait HasSpecialPrice
 *
 * Provides functionality to handle product special pricing,
 * including percentage discounts and date-based availability.
 *
 * @property Money|null $special_price
 * @property PriceType|null $special_price_type
 * @property Carbon|null $special_price_start
 * @property Carbon|null $special_price_end
 */
trait HasSpecialPrice
{
    /**
     * Get the calculated special price as a Money instance.
     *
     * - If the special price type is percentage, it calculates the discounted price.
     * - Ensures the special price does not go below zero.
     *
     * @return Money
     */
    public function getSpecialPrice(): Money
    {
        $specialPrice = $this->attributes['special_price'];

        if ($this->special_price_type === 'percent') {
            $discountedPrice = ($specialPrice / 100) * $this->attributes['price'];
            $specialPrice = $this->attributes['price'] - $discountedPrice;
        }

        if ($specialPrice < 0) {
            $specialPrice = 0;
        }

        return new Money($specialPrice, $this->currency);
    }

    /**
     * Determine if the product has a percentage-based special price.
     *
     * @return bool
     */
    public function hasPercentageSpecialPrice(): bool
    {
        return $this->hasSpecialPrice() && $this->special_price_type === PriceType::Percent;
    }

    /**
     * Determine if the product has a valid special price.
     *
     * - Checks if a special price is set.
     * - If start and/or end dates are defined, validates them against the current date.
     *
     * @return bool
     */
    public function hasSpecialPrice(): bool
    {
        if (is_null($this->special_price)) {
            return false;
        }

        if ($this->hasSpecialPriceStartDate() && $this->hasSpecialPriceEndDate()) {
            return $this->specialPriceStartDateIsValid() && $this->specialPriceEndDateIsValid();
        }

        if ($this->hasSpecialPriceStartDate()) {
            return $this->specialPriceStartDateIsValid();
        }

        if ($this->hasSpecialPriceEndDate()) {
            return $this->specialPriceEndDateIsValid();
        }

        return true;
    }

    /**
     * Check if the `special_price_start` date is set.
     *
     * @return bool
     */
    private function hasSpecialPriceStartDate(): bool
    {
        return !is_null($this->special_price_start);
    }

    /**
     * Check if the `special_price_end` date is set.
     *
     * @return bool
     */
    private function hasSpecialPriceEndDate(): bool
    {
        return !is_null($this->special_price_end);
    }

    /**
     * Validate that today's date is on or after the `special_price_start` date.
     *
     * @return bool
     */
    private function specialPriceStartDateIsValid(): bool
    {
        return today() >= $this->special_price_start;
    }

    /**
     * Validate that today's date is on or before the `special_price_end` date.
     *
     * @return bool
     */
    private function specialPriceEndDateIsValid(): bool
    {
        return today() <= $this->special_price_end;
    }
}
