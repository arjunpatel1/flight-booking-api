<?php

namespace Modules\Menu\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Menu\Services\Menu\MenuService;
use Modules\Menu\Services\Menu\MenuServiceInterface;
use Modules\Menu\Services\OnlineMenu\OnlineMenuService;
use Modules\Menu\Services\OnlineMenu\OnlineMenuServiceInterface;

class DeferredMenuServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: MenuServiceInterface::class,
            concrete: fn($app) => $app->make(MenuService::class)
        );

        $this->app->singleton(
            abstract: OnlineMenuServiceInterface::class,
            concrete: fn($app) => $app->make(OnlineMenuService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            MenuServiceInterface::class,
            OnlineMenuServiceInterface::class
        ];
    }
}
