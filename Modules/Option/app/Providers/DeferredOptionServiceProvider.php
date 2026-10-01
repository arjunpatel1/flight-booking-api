<?php

namespace Modules\Option\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Option\Services\Option\OptionService;
use Modules\Option\Services\Option\OptionServiceInterface;

class DeferredOptionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: OptionServiceInterface::class,
            concrete: fn($app) => $app->make(OptionService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            OptionServiceInterface::class,
        ];
    }
}
