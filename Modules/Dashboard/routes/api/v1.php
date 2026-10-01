<?php


use Modules\Dashboard\Enums\AnalyticsPeriod;
use Modules\Dashboard\Enums\SalesAnalyticsFilter;
use Modules\Dashboard\Http\Controllers\Api\V1\DashboardController;

Route::controller(DashboardController::class)
    ->prefix('dashboards')
    ->group(function () {
        Route::get('overview', 'overview')
            ->middleware('permission:admin.dashboards.total_sales|admin.dashboards.total_orders|admin.dashboards.total_active_orders|admin.dashboards.average_order_value|admin.dashboards.total_users|admin.dashboards.total_menus|admin.dashboards.total_products|admin.dashboards.total_categories');
        Route::get('operational-metrics', 'operationalMetrics')
            ->middleware('permission:admin.dashboards.live|admin.dashboards.analytics');
        Route::get('monitoring', 'monitoring')
            ->middleware("can:admin.dashboards.monitoring");
        Route::get('smart-insights', 'smartInsights')
            ->middleware("can:admin.dashboards.analytics");
        Route::get('demand-forecast', 'demandForecast')
            ->middleware("can:admin.dashboards.analytics");
        Route::get('smart-insight-snapshots', 'smartInsightSnapshots')
            ->middleware("can:admin.dashboards.analytics");
        Route::post('smart-insight-snapshots', 'generateSmartInsightSnapshot')
            ->middleware("can:admin.dashboards.analytics");
        Route::post('assistant', 'assistant')
            ->middleware("can:admin.dashboards.analytics");
        Route::get('sales-analytics/{filter}', 'salesAnalytics')
            ->whereIn("filter", SalesAnalyticsFilter::values())
            ->middleware("can:admin.dashboards.sales_analytics");
        Route::get('best-performing-branches/{filter}', 'bestPerformingBranches')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.best_performing_branches");
        Route::get('order-type-distribution/{filter}', 'orderTypeDistribution')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.order_type_distribution");
        Route::get('order-total-by-status/{filter}', 'orderTotalByStatus')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.order_total_by_status");
        Route::get('payments-overview/{filter}', 'paymentsOverview')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.payments_overview");
        Route::get('hourly-sales-trend', 'hourlySalesTrend')
            ->middleware("can:admin.dashboards.sales_analytics");
        Route::get('branch-wise-sales-comparison/{filter}', 'branchWiseSalesComparison')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.branch_wise_sales_comparison");
        Route::get('cash-movements-overview/{filter}', 'cashMovementsOverview')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.cash_movements_overview");
        Route::get('top-selling-products/{filter}', 'topSellingProducts')
            ->whereIn("filter", AnalyticsPeriod::values())
            ->middleware("can:admin.dashboards.top_selling_products");
        Route::get('low-stock-alerts', 'getLowStockAlerts')
            ->middleware("can:admin.dashboards.low_stock_alerts");
        Route::get('system-health', 'systemHealth')
            ->middleware("can:admin.dashboards.monitoring");
        // admin.dashboards.overview is not a registered permission, so
        // can: denied everyone except super admins (who bypass via
        // Gate::before). That is why global search and the command palette
        // always answered "Could not load server results". Gate them on the
        // same permission set that guards the dashboard overview itself.
        Route::get('global-search', 'globalSearch')
            ->middleware('permission:admin.dashboards.total_sales|admin.dashboards.total_orders|admin.dashboards.total_active_orders|admin.dashboards.average_order_value|admin.dashboards.total_users|admin.dashboards.total_menus|admin.dashboards.total_products|admin.dashboards.total_categories');
        Route::get('command-palette', 'commandPalette')
            ->middleware('permission:admin.dashboards.total_sales|admin.dashboards.total_orders|admin.dashboards.total_active_orders|admin.dashboards.average_order_value|admin.dashboards.total_users|admin.dashboards.total_menus|admin.dashboards.total_products|admin.dashboards.total_categories');
    });
