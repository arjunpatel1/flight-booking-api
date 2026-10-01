<?php

namespace Modules\Branch\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @property string $currency
 */
trait HasBranchCurrency
{
    /**
     * Get currency
     */
    public function currency(): Attribute
    {
        return Attribute::get(
            fn () => $this->relationLoaded('branch')
                ? ($this->branch?->currency ?: $this->defaultCurrency())
                : $this->defaultCurrency()
        );
    }

    private function defaultCurrency(): string
    {
        return setting('default_currency') ?: config('app.currency', 'INR');
    }
}
