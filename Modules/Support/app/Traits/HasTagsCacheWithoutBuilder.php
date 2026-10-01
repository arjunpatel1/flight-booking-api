<?php

namespace Modules\Support\Traits;

use Illuminate\Support\Facades\Cache;

trait HasTagsCacheWithoutBuilder
{
    /**
     * Perform any actions required before the model boots.
     *
     * @return void
     */
    public static function bootHasTagsCacheWithoutBuilder(): void
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
}
