<?php

use Modules\Cart\Http\Controllers\Api\V1\CartController;
use Modules\Cart\Http\Controllers\Api\V1\CartCustomerController;
use Modules\Cart\Http\Controllers\Api\V1\CartDiscountController;
use Modules\Cart\Http\Controllers\Api\V1\CartGiftController;
use Modules\Cart\Http\Controllers\Api\V1\CartItemController;
use Modules\Cart\Http\Controllers\Api\V1\CartOrderTypeController;
use Modules\Cart\Http\Controllers\Api\V1\CartReorderController;
use Modules\Cart\Http\Controllers\Api\V1\CartVoucherController;
use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use Modules\Order\Enums\OrderType;

Route::prefix('cart/{cartId}')
    ->whereUuid('cartId')
    ->middleware(['auth', 'compress', 'can:admin.pos.index', 'validate_cart_ownership'])
    ->group(function () {
        Route::controller(CartController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('meta', 'getMeta');
                Route::delete('clear', 'clear');
            });

        Route::controller(CartItemController::class)
            ->prefix('items')
            ->group(function () {
                Route::post('', 'store');
                Route::post('batch', 'batchStore');
                Route::post('quick', 'quickStore');
                Route::put('{itemId}', 'update');
                Route::delete('{itemId}', 'destroy');
                Route::post('{itemId}/action', 'storeAction');
                Route::delete('{itemId}/action', 'destroyAction');
            });

        Route::controller(CartOrderTypeController::class)
            ->prefix('order-types')
            ->group(function () {
                Route::post('{type}', 'store')
                    ->whereIn('type', OrderType::values());
                Route::delete('', 'destroy');
            });

        Route::controller(CartCustomerController::class)
            ->prefix('customers')
            ->group(function () {
                Route::post('{id}', 'store');
                Route::delete('', 'destroy');
            });

        Route::controller(CartDiscountController::class)
            ->prefix('discounts')
            ->group(function () {
                Route::post('{id}', 'store');
                Route::delete('', 'destroy');
            });

        Route::post('vouchers', [CartVoucherController::class, 'store']);
        Route::post('gifts/{id}', [CartGiftController::class, 'store']);

        Route::post('reorders/{orderId}', [CartReorderController::class, 'repeatSelected'])
            ->middleware(EnsureIdempotentRequest::class.':required');

        Route::controller(CartReorderController::class)
            ->prefix('customers/{customerId}/reorders')
            ->middleware('can:admin.pos.index')
            ->group(function () {
                Route::get('recent', 'recent');
                Route::get('last', 'last');
                Route::post('last', 'repeatLast')
                    ->middleware(EnsureIdempotentRequest::class.':required');
                Route::post('{orderId}', 'repeat')
                    ->middleware(EnsureIdempotentRequest::class.':required');
            });
    });

// Public Cart API - No Authentication Required
Route::prefix('public/cart/{cartId}')
    ->whereUuid('cartId')
    ->withoutMiddleware('auth')
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::controller(\Modules\Cart\Http\Controllers\Api\V1\PublicCartController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('meta', 'getMeta');
                Route::delete('clear', 'clear');
                Route::post('initialize', 'initialize');
            });

        Route::controller(\Modules\Cart\Http\Controllers\Api\V1\PublicCartItemController::class)
            ->prefix('items')
            ->group(function () {
                Route::post('', 'store');
                Route::post('batch', 'batchStore');
                Route::put('{itemId}', 'update');
                Route::delete('{itemId}', 'destroy');
                Route::post('{itemId}/action', 'storeAction');
                Route::delete('{itemId}/action', 'destroyAction');
            });
    });

Route::prefix('customer-app/cart/{cartId}')
    ->whereUuid('cartId')
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([\Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'throttle:120,1'])
    ->group(function () {
        Route::controller(\Modules\Cart\Http\Controllers\Api\V1\PublicCartController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('meta', 'getMeta');
            Route::delete('clear', 'clear');
            Route::post('initialize', 'initialize');
        });
        Route::controller(\Modules\Cart\Http\Controllers\Api\V1\PublicCartItemController::class)
            ->prefix('items')->group(function () {
                Route::post('', 'store');
                Route::post('batch', 'batchStore');
                Route::put('{itemId}', 'update');
                Route::delete('{itemId}', 'destroy');
                Route::post('{itemId}/action', 'storeAction');
                Route::delete('{itemId}/action', 'destroyAction');
            });
    });

// Voucher validation is deliberately signed-in even though menu carts can be
// built anonymously. Per-customer limits and customer targeting are unsafe
// without an authoritative customer identity.
Route::prefix('customer-app/cart/{cartId}')
    ->whereUuid('cartId')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([
        'auth:sanctum',
        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        'tenant.feature:coupons',
        'throttle:30,1',
    ])
    ->group(function () {
        Route::post('vouchers', [\Modules\Cart\Http\Controllers\Api\V1\CustomerCartVoucherController::class, 'store']);
        Route::delete('vouchers', [\Modules\Cart\Http\Controllers\Api\V1\CustomerCartVoucherController::class, 'destroy']);
    });

// Group orders are customer-authenticated and tenant-resolved before any
// invite, group or item lookup. Every mutation also uses the platform's
// persistent idempotency ledger so network retries cannot duplicate writes.
Route::prefix('customer-app/group-orders')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([
        'auth:sanctum',
        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        'tenant.feature:customer_app',
        'throttle:60,1',
    ])
    ->controller(\Modules\Cart\Http\Controllers\Api\V1\CustomerGroupOrderController::class)
    ->group(function () {
        Route::post('broadcasting/auth', 'broadcastingAuth')->middleware('throttle:120,1');
        Route::post('', 'store')->middleware(EnsureIdempotentRequest::class.':required');
        Route::get('invites/{token}', 'resolveInvite')->where('token', '[A-Za-z0-9]{8,128}');
        Route::post('invites/{token}/join', 'join')->where('token', '[A-Za-z0-9]{8,128}')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::get('{groupId}', 'show')->whereUuid('groupId');
        Route::post('{groupId}/items', 'addItem')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::put('{groupId}/items/{itemId}', 'updateItem')->whereUuid('groupId')->whereUuid('itemId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::delete('{groupId}/items/{itemId}', 'removeItem')->whereUuid('groupId')->whereUuid('itemId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('{groupId}/lock', 'lock')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::delete('{groupId}/participants/{participantReference}', 'removeParticipant')->whereUuid('groupId')->whereUuid('participantReference')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('{groupId}/cancel', 'cancel')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('{groupId}/coupon', 'applyCoupon')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::delete('{groupId}/coupon', 'removeCoupon')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('{groupId}/checkout', 'prepareCheckout')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('{groupId}/complete', 'complete')->whereUuid('groupId')
            ->middleware(EnsureIdempotentRequest::class.':required');
    });
