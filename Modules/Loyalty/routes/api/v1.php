<?php


use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyCustomerController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyGiftController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyProgramController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyPromotionController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyRewardController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyTierController;
use Modules\Loyalty\Http\Controllers\Api\V1\LoyaltyTransactionController;

Route::middleware('tenant.feature:loyalty')->group(function () {
Route::controller(LoyaltyProgramController::class)
    ->prefix('loyalty-programs')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_programs.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.loyalty_programs.show|admin.loyalty_programs.edit');
        Route::post('/', 'store')->middleware('can:admin.loyalty_programs.create')->middleware('throttle:30,1');
        Route::put('/{id}', 'update')->middleware('can:admin.loyalty_programs.edit')->middleware('throttle:30,1');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.loyalty_programs.destroy')->middleware('throttle:30,1');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.loyalty_programs.edit|admin.loyalty_programs.create');
    });

Route::controller(LoyaltyTierController::class)
    ->prefix('loyalty-tiers')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_tiers.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.loyalty_tiers.show|admin.loyalty_tiers.edit');
        Route::post('/', 'store')->middleware('can:admin.loyalty_tiers.create')->middleware('throttle:30,1');
        Route::put('/{id}', 'update')->middleware('can:admin.loyalty_tiers.edit')->middleware('throttle:30,1');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.loyalty_tiers.destroy')->middleware('throttle:30,1');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.loyalty_tiers.edit|admin.loyalty_tiers.create');
    });

Route::controller(LoyaltyRewardController::class)
    ->prefix('loyalty-rewards')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_rewards.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.loyalty_rewards.show|admin.loyalty_rewards.edit');
        Route::post('/', 'store')->middleware('can:admin.loyalty_rewards.create')->middleware('throttle:30,1');
        Route::put('/{id}', 'update')->middleware('can:admin.loyalty_rewards.edit')->middleware('throttle:30,1');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.loyalty_rewards.destroy')->middleware('throttle:30,1');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.loyalty_rewards.edit|admin.loyalty_rewards.create');
    });

Route::controller(LoyaltyPromotionController::class)
    ->prefix('loyalty-promotions')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_promotions.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.loyalty_promotions.show|admin.loyalty_promotions.edit');
        Route::post('/', 'store')->middleware('can:admin.loyalty_promotions.create')->middleware('throttle:30,1');
        Route::put('/{id}', 'update')->middleware('can:admin.loyalty_promotions.edit')->middleware('throttle:30,1');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.loyalty_promotions.destroy')->middleware('throttle:30,1');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.loyalty_promotions.edit|admin.loyalty_promotions.create');
    });


Route::controller(LoyaltyCustomerController::class)
    ->prefix('loyalty-customers')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_customers.index');
        Route::get('/{id}', 'show')->middleware('can:admin.loyalty_customers.show');
        Route::post('/{id}/adjust-points', 'adjustPoints')
            ->middleware('can:admin.loyalty_customers.adjust')->middleware('throttle:30,1');
    });

Route::controller(LoyaltyTransactionController::class)
    ->prefix('loyalty-transactions')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_transactions.index');
        Route::get('/{id}', 'show')->middleware('can:admin.loyalty_transactions.show');
    });


Route::controller(LoyaltyGiftController::class)
    ->prefix('loyalty-gifts')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.loyalty_gifts.index');
        Route::get('rewards', 'rewards')->middleware('can:admin.loyalty_gifts.index_rewards');
        Route::post('rewards/{id}/redeem', 'redeem')->middleware('can:admin.loyalty_gifts.redeem')->middleware('throttle:60,1');
        Route::get('available', 'available')->middleware('can:admin.pos.index');
    });
});
