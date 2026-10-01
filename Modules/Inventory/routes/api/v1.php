<?php


use Modules\Inventory\Http\Controllers\Api\V1\IngredientController;
use Modules\Inventory\Http\Controllers\Api\V1\InventoryAnalyticsController;
use Modules\Inventory\Http\Controllers\Api\V1\PurchaseController;
use Modules\Inventory\Http\Controllers\Api\V1\RecipeCostController;
use Modules\Inventory\Http\Controllers\Api\V1\StockMovementController;
use Modules\Inventory\Http\Controllers\Api\V1\SupplierController;
use Modules\Inventory\Http\Controllers\Api\V1\UnitController;
use Modules\Inventory\Http\Controllers\Api\V1\VendorPurchaseController;
use Modules\Inventory\Http\Controllers\Api\V1\WastageTrackingController;

Route::middleware('tenant.feature:inventory')->group(function () {
Route::controller(SupplierController::class)
    ->prefix('suppliers')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.suppliers.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.suppliers.edit|admin.suppliers.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.suppliers.show|admin.suppliers.edit');
        Route::post('/', 'store')->middleware('can:admin.suppliers.create');
        Route::put('/{id}', 'update')->middleware('can:admin.suppliers.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.suppliers.destroy');
    });

Route::controller(UnitController::class)
    ->prefix('units')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.units.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.units.edit|admin.units.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.units.show|admin.units.edit');
        Route::post('/', 'store')->middleware('can:admin.units.create');
        Route::put('/{id}', 'update')->middleware('can:admin.units.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.units.destroy');
    });

Route::controller(IngredientController::class)
    ->prefix('ingredients')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.ingredients.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.ingredients.edit|admin.ingredients.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.ingredients.show|admin.ingredients.edit');
        Route::post('/', 'store')->middleware('can:admin.ingredients.create');
        Route::put('/{id}', 'update')->middleware('can:admin.ingredients.edit');
        Route::post('/{id}/quick-adjust', 'quickAdjust')->middleware('can:admin.ingredients.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.ingredients.destroy');
    });

Route::controller(StockMovementController::class)
    ->prefix('stock-movements')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.stock_movements.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.stock_movements.edit|admin.stock_movements.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.stock_movements.show|admin.stock_movements.edit');
        Route::post('/', 'store')->middleware('can:admin.stock_movements.create');
        Route::put('/{id}', 'update')->middleware('can:admin.stock_movements.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.stock_movements.destroy');
    });

Route::controller(PurchaseController::class)
    ->prefix('purchases')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.purchases.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.purchases.edit|admin.purchases.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.purchases.show|admin.purchases.edit')->name('admin.purchases.show');
        Route::post('/', 'store')->middleware('can:admin.purchases.create');
        Route::put('/{id}', 'update')->middleware('can:admin.purchases.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.purchases.destroy');
        Route::post('/{id}/mark-as-received', 'markAsReceived')->middleware('can:admin.purchases.mark_as_received');
    });

Route::controller(InventoryAnalyticsController::class)
    ->prefix('inventories/analytics')
    ->middleware('can:admin.inventories.analytics')
    ->group(function () {
        Route::get('meta/data', 'getMetaData');
        Route::get('top-suppliers', 'topSuppliers');
        Route::get('ingredient-purchases', 'ingredientPurchases');
        Route::get('stock-movement-summary', 'stockMovementSummary');
        Route::get('wastage-and-spoilage', 'wastageAndSpoilage');
        Route::get('purchase-status-summary', 'purchaseStatusSummary');
        Route::get('low-stock-ingredients', 'lowStockIngredients');
        Route::get('stock-valuation', 'stockValuation');
        Route::get('reorder-suggestions', 'reorderSuggestions');
        Route::get('fast-moving-ingredients', 'fastMovingIngredients');
        Route::get('most-wasted-ingredients', 'mostWastedIngredients');
    });

// Recipe Cost Analysis Routes
Route::controller(RecipeCostController::class)
    ->prefix('recipe-costs')
    ->middleware('can:admin.inventories.recipe_costing')
    ->group(function () {
        Route::get('/form/meta', 'getFormMeta');
        Route::get('/menu-stats', 'getMenuStats');
        Route::get('/products/{productId}/cost', 'getProductCost');
        Route::get('/products/{productId}/recommended-price', 'getRecommendedPrice');
        Route::get('/menu-analysis', 'getMenuAnalysis');
        Route::get('/high-cost-products', 'getHighCostProducts');
    });

// Vendor Purchase Routes
Route::controller(VendorPurchaseController::class)
    ->prefix('vendor-purchases')
    ->middleware('can:admin.purchases.index')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.purchases.index');
        Route::post('/', 'store')->middleware('can:admin.purchases.create');
        Route::get('/vendor-analysis', 'vendorAnalysis')->middleware('can:admin.purchases.index');
        Route::get('/statistics', 'statistics')->middleware('can:admin.purchases.index');
        Route::get('/report', 'report')->middleware('can:admin.purchases.index');
        Route::get('/{id}', 'show')->middleware('can:admin.purchases.show');
        Route::put('/{id}/status', 'updateStatus')->middleware('can:admin.purchases.edit');
        Route::post('/{id}/receive', 'receive')->middleware('can:admin.purchases.edit');
    });

// Wastage Tracking Routes
Route::controller(WastageTrackingController::class)
    ->prefix('wastage')
    ->middleware('can:admin.wastage.index')
    ->group(function () {
        Route::get('/reasons', 'getReasons');
        Route::post('/', 'store')->middleware('can:admin.wastage.create');
        Route::get('/report', 'getReport');
        Route::get('/top-wasted', 'getTopWasted');
        Route::get('/by-reason', 'getByReason');
        Route::get('/trends', 'getTrends');
    });
});
