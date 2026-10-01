<?php

use Modules\Hotels\Http\Controllers\Api\V1\HotelController;

Route::controller(HotelController::class)
    ->prefix('hotels')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.hotels.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.hotels.show|admin.hotels.edit');
        Route::post('/', 'store')->middleware('can:admin.hotels.create');
        Route::put('/{id}', 'update')->middleware('can:admin.hotels.edit');
        Route::delete('/bulk/{ids}', 'destroy')->middleware('can:admin.hotels.destroy');
        Route::delete('/{id}', 'delete')->middleware('can:admin.hotels.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.hotels.edit|admin.hotels.create');
    });
