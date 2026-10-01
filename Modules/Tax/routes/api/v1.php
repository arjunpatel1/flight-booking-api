<?php


use Modules\Tax\Http\Controllers\Api\V1\TaxController;

Route::middleware('tenant.feature:tax')->group(function () {
Route::controller(TaxController::class)
    ->prefix('taxes')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.taxes.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.taxes.edit|admin.taxes.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.taxes.show|admin.taxes.edit');
        Route::post('/', 'store')->middleware('can:admin.taxes.create');
        Route::put('/{id}', 'update')->middleware('can:admin.taxes.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.taxes.destroy');
    });
});
