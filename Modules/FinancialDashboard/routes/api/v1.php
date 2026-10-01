<?php

use Modules\FinancialDashboard\Http\Controllers\Api\V1\FinancialDashboardController;
use Modules\FinancialDashboard\Http\Controllers\Api\V1\FinancialExportController;

Route::prefix('financial-dashboard')->middleware('can:admin.financial_dashboard.index')->group(function () {
    Route::get('kpis', [FinancialDashboardController::class, 'kpis']);
    Route::get('sales-trend', [FinancialDashboardController::class, 'salesTrend']);
    Route::get('profitability', [FinancialDashboardController::class, 'profitability']);
    Route::get('category-analytics', [FinancialDashboardController::class, 'categoryAnalytics']);
    Route::get('top-items', [FinancialDashboardController::class, 'topItems']);
    Route::get('menu-engineering', [FinancialDashboardController::class, 'menuEngineering']);
    Route::get('payment-analytics', [FinancialDashboardController::class, 'paymentAnalytics']);
    Route::get('customer-analytics', [FinancialDashboardController::class, 'customerAnalytics']);
    Route::get('peak-hours', [FinancialDashboardController::class, 'peakHours']);
    Route::get('peak-days', [FinancialDashboardController::class, 'peakDays']);

    Route::get('branch-analytics', [FinancialDashboardController::class, 'branchAnalytics'])
        ->middleware('can:admin.financial_dashboard.branch_analysis');

    Route::post('export', [FinancialExportController::class, 'export'])
        ->middleware('can:admin.financial_dashboard.export');
});
