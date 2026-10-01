<?php

use Modules\Pricing\Http\Controllers\Api\V1\PriceTypeController;

Route::controller(PriceTypeController::class)
    ->prefix('price-types')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.price_types.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.price_types.edit|admin.price_types.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.price_types.show|admin.price_types.edit');
        Route::post('/', 'store')->middleware('can:admin.price_types.create');
        Route::put('/{id}', 'update')->middleware('can:admin.price_types.edit');
        Route::patch('/{id}/toggle-status', 'toggleStatus')->middleware('can:admin.price_types.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.price_types.destroy');
    });
