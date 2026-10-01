<?php

use Modules\Branch\Http\Controllers\Api\V1\BranchController;

Route::controller(BranchController::class)
    ->prefix('branches')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.branches.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.branches.edit|admin.branches.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.branches.show|admin.branches.edit');
        Route::post('/', 'store')->middleware('can:admin.branches.create');
        Route::put('/{id}', 'update')->middleware('can:admin.branches.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.branches.destroy');
    });
