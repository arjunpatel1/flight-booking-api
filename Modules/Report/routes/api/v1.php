<?php

use Modules\Report\Http\Controllers\Api\V1\AnalyticsController;
use Modules\Report\Http\Controllers\Api\V1\GstDashboardController;
use Modules\Report\Http\Controllers\Api\V1\ReportController;
use Modules\Report\Http\Controllers\Api\V1\MonthlySalesReportController;
use Modules\Report\Http\Controllers\Api\V1\ExpenseAnalyticsController;
use Modules\Report\Http\Controllers\Api\V1\ShiftAnalyticsController;
use Modules\Report\Http\Controllers\Api\V1\KitchenAnalyticsController;
use Modules\Report\Http\Controllers\Api\V1\WaiterSettlementController;
use Modules\Report\Http\Controllers\Api\V1\ReportShareController;

Route::middleware('tenant.feature:reports')->group(function () {
Route::controller(WaiterSettlementController::class)
    ->prefix('waiter-settlements')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.waiter_settlements.index');
        Route::get('/preview', 'preview')
            ->middleware('can:admin.waiter_settlements.index');
        Route::post('/', 'store')->middleware('can:admin.waiter_settlements.create');
    });

Route::controller(MonthlySalesReportController::class)
    ->prefix('reports/monthly-sales')
    ->middleware(['can:admin.reports.index', 'can:admin.reports.monthly_sales'])
    ->group(function () {
        Route::get('/', 'show');
        Route::post('/generate', 'generate');
        Route::get('/{report}/download', 'download');
        Route::get('/{report}/view', 'view');
    });

Route::get('gst/dashboard', [GstDashboardController::class, 'index'])
    ->middleware(['can:admin.reports.index', 'can:admin.reports.gst_dashboard']);

Route::get('reports/owner-summary', [ReportController::class, 'ownerSummary'])
    ->middleware('can:admin.reports.index');

Route::get('reports/export-center', [ReportController::class, 'exportCenter'])
    ->middleware('can:admin.reports.index');

Route::post('reports/share/email', [ReportShareController::class, 'email'])
    ->middleware(['can:admin.reports.index', 'throttle:10,1']);

Route::controller(ReportController::class)
    ->prefix('reports/schedules')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/', 'schedules');
        Route::post('/', 'storeSchedule');
        Route::delete('/{schedule}', 'destroySchedule');
    });

Route::controller(ReportController::class)
    ->prefix('reports/{report}')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/export/{method}', 'export');
        Route::get('/saved-filters', 'savedFilters');
        Route::post('/saved-filters', 'storeSavedFilter');
        Route::put('/saved-filters/{filter}', 'updateSavedFilter');
        Route::delete('/saved-filters/{filter}', 'destroySavedFilter');
    });

Route::controller(AnalyticsController::class)
    ->prefix('analytics')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/realtime', 'realtime');
        Route::get('/sales', 'sales');
        Route::get('/profit', 'profit');
        Route::get('/outlets', 'outlets');
        Route::get('/peak-hours', 'peakHours');
        Route::get('/menu-engineering', 'menuEngineering');
        Route::get('/inventory', 'inventory');
        Route::get('/dead-items', 'deadItems');
    });

Route::controller(ExpenseAnalyticsController::class)
    ->prefix('analytics/expense')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/summary', 'getSummary');
        Route::get('/by-category', 'getByCategory');
        Route::get('/daily-trend', 'getDailyTrend');
        Route::get('/outlet-comparison', 'getOutletComparison');
        Route::get('/category-wise', 'getCategoryWiseExpense');
    });

Route::controller(ShiftAnalyticsController::class)
    ->prefix('analytics/shift')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/summary', 'getSummary');
        Route::get('/by-shift', 'getByShift');
        Route::get('/by-user', 'getByUser');
        Route::get('/daily-trend', 'getDailyTrend');
        Route::get('/cash-reconciliation', 'getCashReconciliation');
    });

Route::controller(KitchenAnalyticsController::class)
    ->prefix('analytics/kitchen')
    ->middleware('can:admin.reports.index')
    ->group(function () {
        Route::get('/summary', 'getSummary');
        Route::get('/by-station', 'getByStation');
        Route::get('/daily-trend', 'getDailyTrend');
        Route::get('/peak-hours', 'getPeakHours');
    });
});
