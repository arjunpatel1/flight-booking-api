<?php

namespace Modules\Report\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Report\Services\EnterpriseReportSummary\EnterpriseReportSummaryService;
use Modules\Report\Services\EnterpriseReportSummary\EnterpriseReportSummaryServiceInterface;
use Modules\Report\Services\Report\ReportService;
use Modules\Report\Services\Report\ReportServiceInterface;
use Modules\Report\Services\WaiterSettlement\WaiterSettlementService;
use Modules\Report\Services\WaiterSettlement\WaiterSettlementServiceInterface;

class DeferredReportServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: ReportServiceInterface::class,
            concrete: fn($app) => $app->make(ReportService::class)
        );

        $this->app->singleton(
            abstract: EnterpriseReportSummaryServiceInterface::class,
            concrete: fn($app) => $app->make(EnterpriseReportSummaryService::class)
        );

        $this->app->singleton(
            abstract: WaiterSettlementServiceInterface::class,
            concrete: fn($app) => $app->make(WaiterSettlementService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            EnterpriseReportSummaryServiceInterface::class,
            ReportServiceInterface::class,
            WaiterSettlementServiceInterface::class,
        ];
    }
}
