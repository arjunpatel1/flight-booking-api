<?php

namespace Modules\Loyalty\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Loyalty\Console\ExpireLoyaltyGifts;
use Modules\Loyalty\Console\ExpireLoyaltyPoints;

class LoyaltyServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireLoyaltyPoints::class,
                ExpireLoyaltyGifts::class
            ]);
            $this->registerScheduler();
        }
    }

    private function registerScheduler(): void
    {
        $this->app->booted(function ($app) {
            $app[Schedule::class]
                ->command(ExpireLoyaltyPoints::class)
                ->dailyAt('00:30')
                ->withoutOverlapping();

            $app[Schedule::class]
                ->command(ExpireLoyaltyGifts::class)
                ->dailyAt('02:00')
                ->withoutOverlapping();
        });
    }
}
