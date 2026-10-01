<?php

namespace Modules\Product\Traits;

use Carbon\Carbon;

/**
 * Trait IsNew
 *
 * Provides logic to determine if a product is considered "new"
 * based on the optional `new_from` and `new_to` date attributes.
 *
 * @property Carbon|null $new_from
 *
 * @property Carbon|null $new_to
 *
 */
trait IsNew
{
    /**
     * Determine if the product is currently considered "new"
     * based on the `new_from` and `new_to` date range.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        if ($this->hasNewFromDate() && $this->hasNewToDate()) {
            return $this->newFromDateIsValid() && $this->newToDateIsValid();
        }

        if ($this->hasNewFromDate()) {
            return $this->newFromDateIsValid();
        }

        if ($this->hasNewToDate()) {
            return $this->newToDateIsValid();
        }

        return false;
    }

    /**
     * Check if the `new_from` date is set.
     *
     * @return bool
     */
    private function hasNewFromDate(): bool
    {
        return !is_null($this->new_from);
    }

    /**
     * Check if the `new_to` date is set.
     *
     * @return bool
     */
    private function hasNewToDate(): bool
    {
        return !is_null($this->new_to);
    }

    /**
     * Validate that the current date is on or after the `new_from` date.
     *
     * @return bool
     */
    private function newFromDateIsValid(): bool
    {
        return today() >= $this->new_from;
    }

    /**
     * Validate that the current date is on or before the `new_to` date.
     *
     * @return bool
     */
    private function newToDateIsValid(): bool
    {
        return today() <= $this->new_to;
    }
}
