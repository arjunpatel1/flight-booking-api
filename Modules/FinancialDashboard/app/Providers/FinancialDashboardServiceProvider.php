<?php

namespace Modules\FinancialDashboard\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\FinancialDashboard\Services\FinancialDashboardService;

class FinancialDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FinancialDashboardService::class);
    }
}
