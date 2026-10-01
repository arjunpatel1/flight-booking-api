<?php

namespace Modules\ActivityLog\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\ActivityLog\Services\ActivityLog\ActivityLogService;
use Modules\ActivityLog\Services\ActivityLog\ActivityLogServiceInterface;
use Modules\ActivityLog\Services\AuthenticationLog\AuthenticationLogService;
use Modules\ActivityLog\Services\AuthenticationLog\AuthenticationLogServiceInterface;

class DeferredActivityLogServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: ActivityLogServiceInterface::class,
            concrete: fn($app) => $app->make(ActivityLogService::class)
        );

        $this->app->singleton(
            abstract: AuthenticationLogServiceInterface::class,
            concrete: fn($app) => $app->make(AuthenticationLogService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            ActivityLogServiceInterface::class,
            AuthenticationLogServiceInterface::class
        ];
    }
}
