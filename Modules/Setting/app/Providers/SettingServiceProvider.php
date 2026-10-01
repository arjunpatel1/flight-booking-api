<?php

namespace Modules\Setting\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Setting\Commands\CreateSystemBackup;

class SettingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateSystemBackup::class,
            ]);
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('system:backup')
                ->dailyAt('01:30')
                ->withoutOverlapping()
                ->when(fn() => (bool) setting('system_backup_enabled', false));
        });
    }
}
