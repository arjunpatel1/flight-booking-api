<?php

use Modules\Product\Http\Controllers\Api\V1\ProductController;
use Modules\Product\Http\Controllers\Api\V1\ProductFavoriteController;
use Modules\Product\Http\Controllers\Api\V1\ImageOptimizationController;

Route::middleware('tenant.feature:pos,waiter_app')->group(function () {
Route::controller(ProductController::class)
    ->prefix('products')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.products.index');
        Route::get('/merchandising/recommendations', 'merchandisingRecommendations')->middleware('can:admin.products.edit');
        Route::post('/merchandising/apply-recommendations', 'applyMerchandisingRecommendations')->middleware('can:admin.products.edit');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.products.edit|admin.products.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.products.show|admin.products.edit');
        Route::post('/', 'store')->middleware('can:admin.products.create');
        Route::put('/{id}', 'update')->middleware('can:admin.products.edit');
        Route::put('/{id}/availability', 'updateAvailability')->middleware('can:admin.products.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.products.destroy');
    });

Route::controller(ProductFavoriteController::class)
    ->prefix('product-favorites')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.pos.index');
        Route::post('/', 'store')->middleware('can:admin.product_favorites.create');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.product_favorites.destroy');
        Route::post('/toggle', 'toggle')->middleware('can:admin.pos.index');
        Route::post('/check', 'check')->middleware('can:admin.pos.index');
    });

Route::controller(ImageOptimizationController::class)
    ->prefix('images/optimize')
    ->middleware('permission:admin.products.create|admin.products.edit')
    ->group(function () {
        Route::post('/upload', 'upload');
        Route::post('/', 'optimize');
        Route::get('/status', 'status');
        Route::delete('/', 'delete');
    });
});
