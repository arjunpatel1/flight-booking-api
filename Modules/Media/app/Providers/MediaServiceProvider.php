<?php

namespace Modules\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Media\Console\FixMediaTreeCommand;
use Modules\Media\Console\MigrateTenantMediaCommand;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FixMediaTreeCommand::class,
                MigrateTenantMediaCommand::class,
            ]);
        }
    }
}
