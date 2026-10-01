<?php

namespace Modules\Currency\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Currency\Listeners\CreateCurrencyRates;
use Modules\Setting\Events\SettingSaved;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        SettingSaved::class => [
            CreateCurrencyRates::class,
        ],
    ];
}
