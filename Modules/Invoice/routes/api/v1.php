<?php


use Modules\Invoice\Http\Controllers\Api\V1\InvoiceController;

Route::middleware('tenant.feature:invoices')->group(function () {
Route::controller(InvoiceController::class)
    ->prefix('invoices')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.invoices.index');
        Route::get('/summary', 'summary')->middleware('can:admin.invoices.index');
        Route::get('/export', 'export')->middleware('can:admin.invoices.index');
        Route::get('/{reference}/show', 'show')->middleware('can:admin.invoices.show');
    });
});
