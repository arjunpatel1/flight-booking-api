<?php

namespace Modules\Support\Traits;

use Illuminate\Support\Facades\Cache;
use Modules\Support\Eloquent\Builder;

trait HasTagsCache
{
    /**
     * Perform any actions required before the model boots.
     *
     * @return void
     */
    public static function bootHasTagsCache(): void
    {
        static::saved(function ($model) {
            $model->clearModelTaggedCache();
        });

        static::deleted(function ($model) {
            $model->clearModelTaggedCache();
        });
    }

    /**
     * Clear model tagged cache
     *
     * @return void
     */
    public function clearModelTaggedCache(): void
    {
        // Create another method since the "clearModelTaggedCache" method might be overridden
        $this->clearDefaultModelTaggedCache();
    }

    /**
     * Clear default model tagged cache
     *
     * @return void
     */
    public function clearDefaultModelTaggedCache(): void
    {
        Cache::tags($this->getTable())->flush();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder|Builder
     */
    public function newEloquentBuilder($query): \Illuminate\Database\Eloquent\Builder|Builder
    {
        return new Builder($query);
    }
}
