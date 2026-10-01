<?php

use Modules\Category\Http\Controllers\Api\V1\CategoryController;

Route::middleware('tenant.feature:pos,waiter_app')->group(function () {
Route::controller(CategoryController::class)
    ->prefix('categories')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.categories.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.categories.show|admin.categories.edit');
        Route::post('/', 'store')->middleware('can:admin.categories.create');
        Route::put('/{id}', 'update')->middleware('can:admin.categories.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.categories.destroy');
        Route::put('{menuId}/tree', 'updateTree')->middleware('can:admin.categories.edit');
    });
});
