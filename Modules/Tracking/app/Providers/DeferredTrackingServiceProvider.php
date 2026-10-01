<?php

namespace Modules\Tracking\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Tracking\Services\LiveTrackingService;
use Modules\Tracking\Services\LiveTrackingServiceInterface;

class DeferredTrackingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(
            abstract: LiveTrackingServiceInterface::class,
            concrete: fn($app) => $app->make(LiveTrackingService::class)
        );
    }

    public function provides(): array
    {
        return [LiveTrackingServiceInterface::class];
    }
}
