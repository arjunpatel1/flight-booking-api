<?php

namespace Modules\Inventory\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Commands\CheckLowStockAlerts;
use Modules\Inventory\Services\RecipeCost\RecipeCostCalculator;
use Modules\Inventory\Services\RecipeCost\RecipeCostCalculatorInterface;
use Modules\Inventory\Services\RecipeDeduction\RecipeDeductionService;
use Modules\Inventory\Services\RecipeDeduction\RecipeDeductionServiceInterface;
use Modules\Inventory\Services\WastageTracking\WastageTrackingService;
use Modules\Inventory\Services\WastageTracking\WastageTrackingServiceInterface;

class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Recipe Cost Calculator
        $this->app->singleton(RecipeCostCalculatorInterface::class, RecipeCostCalculator::class);

        // Recipe Deduction Service
        $this->app->singleton(RecipeDeductionServiceInterface::class, RecipeDeductionService::class);

        // Wastage Tracking Service
        $this->app->singleton(WastageTrackingServiceInterface::class, WastageTrackingService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckLowStockAlerts::class,
            ]);
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('inventory:check-low-stock --limit=200')
                ->hourly()
                ->withoutOverlapping()
                ->when(fn() => (bool) setting('inventory_low_stock_alerts_enabled', true));
        });
    }
}
