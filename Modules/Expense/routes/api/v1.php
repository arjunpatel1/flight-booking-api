<?php

use Illuminate\Support\Facades\Route;
use Modules\Expense\Http\Controllers\Api\V1\ExpenseCategoryController;
use Modules\Expense\Http\Controllers\Api\V1\ExpenseController;

Route::controller(ExpenseCategoryController::class)
    ->prefix('expense-categories')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.expenses.index');
    });

Route::controller(ExpenseController::class)
    ->prefix('expenses')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.expenses.index');
        Route::get('/form/meta', 'getFormMeta')
            ->middleware('permission:admin.expenses.edit|admin.expenses.create');
        Route::get('/{id}', 'show')
            ->middleware('permission:admin.expenses.show|admin.expenses.edit');
        Route::post('/', 'store')->middleware('can:admin.expenses.create');
        Route::put('/{id}', 'update')->middleware('can:admin.expenses.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.expenses.destroy');
    });
