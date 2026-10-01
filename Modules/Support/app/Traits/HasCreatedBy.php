<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/**
 * @property int|null $created_by
 * @property User|null $createdBy
 *
 * @method static Builder whereCreatedBy(int $createdBy)
 */
trait HasCreatedBy
{
    /**
     * The created by key.
     *
     * @var string
     */
    const CREATED_BY_COLUMN_NAME = 'created_by';

    /**
     * Boot the HasCreatedBy trait for a model.
     *
     * @return void
     */
    public static function bootHasCreatedBy(): void
    {
        static::creating(function (Model $model) {
            if (is_null($model->{self::CREATED_BY_COLUMN_NAME})) {
                $model->{self::CREATED_BY_COLUMN_NAME} = auth()->id();
            }
        });
    }

    /**
     * Add created by global scope
     *
     * @return void
     */
    public static function addCreatedByGlobalScope(): void
    {
        static::addGlobalScope('created_by', fn($query) => $query->whereCreatedBy(auth()->id()));
    }

    /**
     * Get a model created by
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, self::CREATED_BY_COLUMN_NAME)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Determine if the system created a model
     *
     * @return bool
     */
    public function wasCreatedBySystem(): bool
    {
        return is_null($this->{self::CREATED_BY_COLUMN_NAME});
    }

    /**
     * Scope a query to only include records created by the specified user.
     *
     * @param Builder $query
     * @param int $createdBy
     * @return void
     */
    public function scopeWhereCreatedBy(Builder $query, int $createdBy): void
    {
        $query->where(static::CREATED_BY_COLUMN_NAME, $createdBy);
    }
}
