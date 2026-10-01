<?php

use Modules\Currency\Http\Controllers\Api\V1\CurrencyRateController;


Route::controller(CurrencyRateController::class)
    ->prefix('currency-rates')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.currency_rates.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.currency_rates.show|admin.currency_rates.edit');
        Route::put('/{id}', 'update')->middleware('can:admin.currency_rates.edit');
        Route::put('/refresh', 'refresh')->middleware('can:admin.currency_rates.edit');
    });
