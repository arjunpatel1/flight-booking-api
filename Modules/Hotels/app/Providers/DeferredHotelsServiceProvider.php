<?php

namespace Modules\Hotels\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Hotels\Services\Hotel\HotelService;
use Modules\Hotels\Services\Hotel\HotelServiceInterface;

class DeferredHotelsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: HotelServiceInterface::class,
            concrete: fn($app) => $app->make(HotelService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            HotelServiceInterface::class
        ];
    }
}
