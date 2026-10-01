<?php

use Modules\Option\Http\Controllers\Api\V1\OptionController;

Route::middleware('tenant.feature:pos,waiter_app')->group(function () {
Route::controller(OptionController::class)
    ->prefix('options')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.options.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.options.show|admin.options.edit');
        Route::post('/', 'store')->middleware('can:admin.options.create');
        Route::put('/{id}', 'update')->middleware('can:admin.options.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.options.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.options.edit|admin.options.create');
    });
});
