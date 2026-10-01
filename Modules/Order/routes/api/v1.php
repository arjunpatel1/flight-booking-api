<?php

use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use Modules\Order\Http\Controllers\Api\V1\OrderController;
use Modules\Order\Http\Controllers\Api\V1\OrderFeedbackController;
use Modules\Order\Http\Controllers\Api\V1\ReasonController;
use Modules\Order\Http\Controllers\Api\V1\UengageWebhookController;
use Modules\Printer\Enum\PrintContentType;

Route::middleware('tenant.feature:pos,online_ordering,waiter_app')->group(function () {
Route::controller(OrderController::class)
    ->prefix('orders')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.orders.index');
        Route::get('/export', 'export')->middleware('can:admin.orders.index');
        Route::get('stats', 'stats')
            ->middleware('can:admin.orders.index');
        Route::get('active', 'activeOrders')
            ->middleware('can:admin.orders.active');
        Route::get('upcoming', 'upcomingOrders')
            ->middleware('can:admin.orders.upcoming');
        Route::get('my-orders', 'customerOrders')
            ->middleware('auth');
        Route::get('/{orderId}/show', 'show')->middleware('can:admin.orders.show');
        Route::prefix('/{orderId}/print')
            ->middleware('can:admin.orders.print')
            ->group(function () {
                Route::get('/', 'printMeta');
                Route::post('{type}', 'print')
                    ->middleware(EnsureIdempotentRequest::class.':required')
                    ->whereIn('type', PrintContentType::values());
                Route::get('{type}/preview', 'previewPrint')
                    ->whereIn('type', PrintContentType::values());
            });
        Route::get('/{cartId}/{orderId}/edit', 'edit')
            ->whereUuid('cartId')
            ->middleware('can:admin.orders.edit');
        Route::post('{cartId}', 'store')
            ->whereUuid('cartId')
            ->middleware(['can:admin.orders.create', EnsureIdempotentRequest::class.':required']);
        Route::put('/{cartId}/{orderId}/update', 'update')
            ->middleware(['can:admin.orders.edit', EnsureIdempotentRequest::class.':required']);
        Route::post('/{orderId}/cancel', 'cancel')
            ->middleware(['can:admin.orders.cancel', EnsureIdempotentRequest::class.':required']);
        Route::post('/{orderId}/refund', 'refund')
            ->middleware(['can:admin.orders.refund', EnsureIdempotentRequest::class.':required']);
        Route::delete('/{orderId}', 'destroy')
            ->middleware('can:admin.orders.destroy');
        Route::post('/{orderId}/split', 'split')
            ->middleware(['can:admin.orders.split', EnsureIdempotentRequest::class.':required']);
        Route::post('/{orderId}/finalize-kot', 'finalizeKOT')
            ->middleware(['can:admin.orders.edit', EnsureIdempotentRequest::class.':required']);
        Route::post('/{orderId}/fire-course', 'fireCourse')
            ->middleware(['can:admin.orders.edit', EnsureIdempotentRequest::class.':required']);
        Route::post('/{orderId}/reprint-bill', 'reprintBill')
            ->whereUuid('orderId')
            ->middleware('can:admin.orders.print');
        Route::get('/{orderId}/update-status/meta', 'getUpdateStatusMeta')
            ->middleware('permission:admin.orders.cancel|admin.orders.refund');

        Route::prefix('{orderId}/payments')
            ->middleware('can:admin.orders.receive_payment')
            ->group(function () {
                Route::post('', 'storePayment')
                    ->middleware(EnsureIdempotentRequest::class.':required');
                Route::get('meta', 'getPaymentMeta');
                Route::get('{paymentId}/slip', 'paymentSlip')->whereNumber('paymentId');
            });

        Route::patch('/{orderId}/move-to-next-status', 'moveToNextStatus')
            ->middleware(['can:admin.orders.update_status', EnsureIdempotentRequest::class.':required']);
        Route::post('/bulk-update-status', 'bulkUpdateStatus')
            ->middleware(['can:admin.orders.update_status', EnsureIdempotentRequest::class.':required']);
    });

Route::controller(OrderFeedbackController::class)
    ->prefix('orders/feedback')
    ->middleware('can:admin.orders.index')
    ->group(function () {
        Route::get('', 'index');
        Route::get('stats', 'stats');
    });

Route::post('orders/feedback', [OrderFeedbackController::class, 'store'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([\Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'throttle:20,1']);

Route::controller(ReasonController::class)
    ->prefix('reasons')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.reasons.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.reasons.show|admin.reasons.edit');
        Route::post('/', 'store')->middleware('can:admin.reasons.create');
        Route::put('/{id}', 'update')->middleware('can:admin.reasons.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.reasons.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.reasons.edit|admin.reasons.create');
    });
});

// Signed-in customer order history.
//
// Registered in its own group because the QR-ordering group above excludes
// `auth:sanctum` wholesale; a route-level guard added inside it is silently
// stripped by that exclusion, leaving the endpoint unauthenticated. Sanctum
// must also run before ResolveCustomerAppContext, which reads the resolved
// customer off the request.
Route::get('customer-app/orders/token-tracking/{reference}', [OrderController::class, 'tokenTracking'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:60,1');

Route::middleware([
        'auth:sanctum',
        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        'tenant.feature:pos,online_ordering,waiter_app',
    ])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->group(function () {
        Route::get('customer-app/orders', [OrderController::class, 'customerAppOrders'])
            ->middleware('throttle:60,1');
        Route::get('customer-app/orders/tracking/{reference}', [OrderController::class, 'publicTracking'])
            ->middleware('throttle:60,1');
        Route::post('customer-app/delivery/quote/{cartId}', [OrderController::class, 'customerDeliveryQuote'])
            ->whereUuid('cartId')
            ->middleware(['throttle:60,1', 'tenant.feature:delivery']);
        Route::get('customer-app/delivery/location-search', [OrderController::class, 'customerDeliveryLocationSearch'])
            ->middleware(['throttle:15,1', 'tenant.feature:delivery']);
        Route::get('customer-app/orders/{reference}/edit/{cartId}', [OrderController::class, 'customerAppEdit'])
            ->whereUuid('cartId')
            ->middleware('throttle:20,1');
        Route::put('customer-app/orders/{reference}/edit/{cartId}', [OrderController::class, 'customerAppUpdate'])
            ->whereUuid('cartId')
            ->middleware(['throttle:10,1', EnsureIdempotentRequest::class.':required']);
        Route::post('customer-app/orders/{cartId}', [OrderController::class, 'publicQrStore'])
            ->whereUuid('cartId')
            ->middleware(['throttle:20,1', EnsureIdempotentRequest::class.':required']);
        Route::post('customer-app/orders/{reference}/cancel', [OrderController::class, 'customerAppCancel'])
            ->middleware(['throttle:10,1', EnsureIdempotentRequest::class.':required']);
        Route::post('customer-app/orders/feedback', [OrderFeedbackController::class, 'store'])
            ->middleware('throttle:20,1');
    });

// The application context must resolve the authoritative tenant before any
// tenant entitlement middleware or resource lookup executes.
Route::middleware([\Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'tenant.feature:pos,online_ordering,waiter_app'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->group(function () {
    });

Route::get('customer-app/orders/{token}/cancel', [OrderController::class, 'customerCancelLink'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:20,1')
    ->name('api.customer-order.cancel-link');

Route::get('staff/order-open/{reference}', [OrderController::class, 'staffOrderLink'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:30,1')
    ->name('api.staff.order-open');

Route::get('customer-app/order-payment/{token}', [OrderController::class, 'customerPaymentLink'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:20,1')
    ->name('api.customer-order.payment-button');

Route::get('customer-app/order-cancel/{token}', [OrderController::class, 'customerCancelLink'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:20,1')
    ->name('api.customer-order.cancel-button');

Route::get('customer-app/order-cancel/{token}/status', [OrderController::class, 'customerCancelStatus'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:20,1')
    ->name('api.customer-order.cancel-status');

Route::post('delivery/uengage/webhook', UengageWebhookController::class)
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:30,1')
    ->name('api.delivery.uengage.webhook');
