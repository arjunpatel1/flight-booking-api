<?php

namespace Modules\Category\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Category\Services\Category\CategoryService;
use Modules\Category\Services\Category\CategoryServiceInterface;
use Modules\Category\Services\CategoryTreeUpdater\CategoryTreeUpdaterService;
use Modules\Category\Services\CategoryTreeUpdater\CategoryTreeUpdaterServiceInterface;

class DeferredCategoryServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: CategoryServiceInterface::class,
            concrete: fn($app) => $app->make(CategoryService::class)
        );
        $this->app->singleton(
            abstract: CategoryTreeUpdaterServiceInterface::class,
            concrete: fn($app) => $app->make(CategoryTreeUpdaterService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            CategoryServiceInterface::class,
            CategoryTreeUpdaterServiceInterface::class
        ];
    }
}
