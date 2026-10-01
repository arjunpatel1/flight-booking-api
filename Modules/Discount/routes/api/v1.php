<?php

use Modules\Discount\Http\Controllers\Api\V1\DiscountController;

Route::middleware('tenant.feature:coupons')->group(function () {
Route::controller(DiscountController::class)
    ->prefix('discounts')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.discounts.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.discounts.show|admin.discounts.edit');
        Route::post('/', 'store')->middleware('can:admin.discounts.create');
        Route::put('/{id}', 'update')->middleware('can:admin.discounts.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.discounts.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.discounts.edit|admin.discounts.create');
    });
});
