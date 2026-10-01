<?php

namespace Modules\Discount\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Discount\Services\Discount\DiscountService;
use Modules\Discount\Services\Discount\DiscountServiceInterface;

class DeferredDiscountServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: DiscountServiceInterface::class,
            concrete: fn($app) => $app->make(DiscountService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DiscountServiceInterface::class,
        ];
    }
}
