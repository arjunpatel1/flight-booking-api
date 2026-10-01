<?php

use Modules\Invoice\Http\Controllers\InvoicePDFController;

Route::prefix('i/{uuid}')
    ->whereUuid('uuid')
    ->controller(InvoicePDFController::class)
    ->name('invoices.pdf.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('download', 'download')->name('download');
    });
