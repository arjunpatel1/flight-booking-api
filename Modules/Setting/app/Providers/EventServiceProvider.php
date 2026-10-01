<?php

namespace Modules\Setting\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Setting\Events\SettingSaved;
use Modules\Setting\Listeners\ClearSettingCache;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        SettingSaved::class => [
            ClearSettingCache::class,
        ],
    ];
}
