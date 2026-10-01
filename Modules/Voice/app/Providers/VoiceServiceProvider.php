<?php

namespace Modules\Voice\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Voice\Services\VoiceAnnouncementService;

class VoiceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(VoiceAnnouncementService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'voice');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'voice');
    }
}
