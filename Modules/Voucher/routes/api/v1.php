<?php

use Modules\Voucher\Http\Controllers\Api\V1\GiftCardController;
use Modules\Voucher\Http\Controllers\Api\V1\VoucherController;

Route::middleware('tenant.feature:coupons,gift_cards')->group(function () {
Route::controller(VoucherController::class)
    ->prefix('vouchers')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.vouchers.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.vouchers.edit|admin.vouchers.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.vouchers.show|admin.vouchers.edit');
        Route::post('/', 'store')->middleware('can:admin.vouchers.create');
        Route::put('/{id}', 'update')->middleware('can:admin.vouchers.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.vouchers.destroy');
    });

Route::controller(GiftCardController::class)
    ->prefix('gift-cards')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.gift_cards.index');
        Route::get('/analytics', 'analytics')->middleware('can:admin.gift_cards.analytics');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.gift_cards.create|admin.gift_cards.edit');
        Route::get('/{id}', 'show')->middleware('permission:admin.gift_cards.show|admin.gift_cards.edit');
        Route::post('/', 'store')->middleware('can:admin.gift_cards.create')->middleware('throttle:30,1');
        Route::put('/{id}', 'update')->middleware('can:admin.gift_cards.edit')->middleware('throttle:30,1');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.gift_cards.destroy')->middleware('throttle:30,1');
        Route::post('/{id}/redeem', 'redeem')->middleware('can:admin.gift_cards.redeem')->middleware('throttle:60,1');
        Route::post('/{id}/top-up', 'topUp')->middleware('can:admin.gift_cards.top_up')->middleware('throttle:30,1');
    });
});
