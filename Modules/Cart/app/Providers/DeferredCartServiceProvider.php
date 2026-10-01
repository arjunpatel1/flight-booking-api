<?php

namespace Modules\Cart\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyService;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;

class DeferredCartServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: DiscountApplyServiceInterface::class,
            concrete: fn($app) => $app->make(DiscountApplyService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DiscountApplyServiceInterface::class,
        ];
    }
}
