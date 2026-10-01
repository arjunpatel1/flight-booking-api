<?php

namespace Modules\Product\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Product\Services\Product\ProductService;
use Modules\Product\Services\Product\ProductServiceInterface;
use Modules\Product\Services\ProductFavorite\ProductFavoriteService;
use Modules\Product\Services\ProductFavorite\ProductFavoriteServiceInterface;
use Modules\Product\Services\ProductIngredient\ProductIngredientService;
use Modules\Product\Services\ProductIngredient\ProductIngredientServiceInterface;
use Modules\Product\Services\ProductOption\ProductOptionService;
use Modules\Product\Services\ProductOption\ProductOptionServiceInterface;

class DeferredProductServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: ProductServiceInterface::class,
            concrete: fn($app) => $app->make(ProductService::class)
        );

        $this->app->singleton(
            abstract: ProductOptionServiceInterface::class,
            concrete: fn($app) => $app->make(ProductOptionService::class)
        );

        $this->app->singleton(
            abstract: ProductIngredientServiceInterface::class,
            concrete: fn($app) => $app->make(ProductIngredientService::class)
        );

        $this->app->singleton(
            abstract: ProductFavoriteServiceInterface::class,
            concrete: fn($app) => $app->make(ProductFavoriteService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            ProductServiceInterface::class,
            ProductOptionServiceInterface::class,
            ProductIngredientServiceInterface::class,
            ProductFavoriteServiceInterface::class,
        ];
    }
}
