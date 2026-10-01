<?php

namespace Modules\Report\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Report\Commands\BuildAnalyticsFactTables;
use Modules\Report\Commands\BuildExpenseFactTables;
use Modules\Report\Commands\BuildShiftFactTables;
use Modules\Report\Commands\BuildKitchenFactTables;
use Modules\Report\Commands\RebuildEnterpriseDailyReportSummary;
use Modules\Report\ReportManager;
use Modules\Report\Services\Expense\ExpenseAnalyticsService;
use Modules\Report\Services\Shift\ShiftAnalyticsService;
use Modules\Report\Services\Kitchen\KitchenAnalyticsService;

class ReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Transient (bind, not singleton) so Octane workers never share report state
        // (branch filters, currency, auth context) across requests.
        $this->app->bind(ReportManager::class, function () {
            $manager = new ReportManager();
            $manager->register(true);
            return $manager;
        });

        $this->app->singleton(ExpenseAnalyticsService::class);
        $this->app->singleton(ShiftAnalyticsService::class);
        $this->app->singleton(KitchenAnalyticsService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildEnterpriseDailyReportSummary::class,
                BuildAnalyticsFactTables::class,
                BuildExpenseFactTables::class,
                BuildShiftFactTables::class,
                BuildKitchenFactTables::class,
            ]);
        }
    }
}
