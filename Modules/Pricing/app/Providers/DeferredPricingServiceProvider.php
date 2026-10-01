<?php

namespace Modules\Pricing\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Pricing\Services\PriceType\PriceTypeService;
use Modules\Pricing\Services\PriceType\PriceTypeServiceInterface;
use Modules\Pricing\Services\ProductPriceResolver\ProductPriceResolverService;
use Modules\Pricing\Services\ProductPriceResolver\ProductPriceResolverServiceInterface;

class DeferredPricingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: PriceTypeServiceInterface::class,
            concrete: fn($app) => $app->make(PriceTypeService::class)
        );

        $this->app->singleton(
            abstract: ProductPriceResolverServiceInterface::class,
            concrete: fn($app) => $app->make(ProductPriceResolverService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            PriceTypeServiceInterface::class,
            ProductPriceResolverServiceInterface::class,
        ];
    }
}
