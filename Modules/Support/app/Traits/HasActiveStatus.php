<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use Modules\Support\Scopes\ActiveScope;

/**
 * @property bool $is_active
 *
 * @method static Builder|static active()
 * @method static Builder|static InActive()
 * @method static Builder|static withoutGlobalActive()
 */
trait HasActiveStatus
{
    /**
     * The status key for active state.
     *
     * @var string
     */
    const ACTIVE_COLUMN_NAME = 'is_active';

    /**
     * Boot HasActiveStatus trait
     *
     * @return void
     */
    public static function bootHasActiveStatus(): void
    {
        static::addGlobalScope(ActiveScope::class);
    }

    /**
     * Determine if a model is not active
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        return !$this->isActive();
    }

    /**
     * Determine if a model is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Scope a query to only include Inactive model's.
     *
     * @param Builder $query
     * @return void
     */
    public function scopeInActive(Builder $query): void
    {
        $this->scopeActive($query, false);
    }

    /**
     * Scope a query to only include active model's.
     *
     * @param Builder $query
     * @param bool|string|null $status
     * @return void
     */
    public function scopeActive(Builder $query, bool|string|null $status = true): void
    {
        $query->where(self::ACTIVE_COLUMN_NAME, $status);
    }

    /**
     * Scope a query without global active scope.
     *
     * @param Builder $query
     * @return void
     */
    public function scopeWithOutGlobalActive(Builder $query): void
    {
        $query->withOutGlobalScope(ActiveScope::class);
    }
}
