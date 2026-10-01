<?php

namespace Modules\Dashboard\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Dashboard\Services\Dashboard\DashboardService;
use Modules\Dashboard\Services\Dashboard\DashboardServiceInterface;

class DeferredDashboardServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: DashboardServiceInterface::class,
            concrete: fn($app) => $app->make(DashboardService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DashboardServiceInterface::class,
        ];
    }
}
