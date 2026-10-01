<?php


use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use Modules\Payment\Http\Controllers\Api\V1\PaymentController;
use Modules\Payment\Http\Controllers\Api\V1\TenantPaymentGatewayController;
use Modules\Payment\Http\Controllers\Api\V1\DirectUpiController;
use Modules\Payment\Http\Controllers\Api\V1\RazorpayCheckoutController;

Route::post('payment-gateways/direct-upi/webhook/{webhookKey}', [DirectUpiController::class, 'webhook'])
    ->whereUuid('webhookKey')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');
Route::post('payment-gateways/razorpay/partner/webhook', [RazorpayCheckoutController::class, 'partnerWebhook'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');

Route::prefix('payment-gateway-settings')->middleware(['can:admin.settings.edit', 'throttle:60,1'])->group(function () {
    Route::get('/', [TenantPaymentGatewayController::class, 'index']);
    Route::put('/checkout/options', [TenantPaymentGatewayController::class, 'updateCheckoutOptions']);
    Route::put('/{provider}', [TenantPaymentGatewayController::class, 'update']);
    Route::post('/{provider}/test', [TenantPaymentGatewayController::class, 'test'])->middleware('throttle:10,1');
});

// Customer checkout uses only an opaque order reference and a server-created
// payment session. The browser never receives tenant gateway credentials and
// a UPI return URL never marks an order paid; the signed webhook does that.
// Payment capability discovery contains no credentials and is required by
// anonymous checkout. Tenant and branch ownership are still derived from the
// signed tenant context plus an opaque menu reference.
Route::get('customer-app/payments/options', [RazorpayCheckoutController::class, 'options'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:60,1');

Route::middleware('tenant.feature:payments')->group(function () {
Route::prefix('direct-upi')->middleware(['auth', 'can:admin.orders.receive_payment', 'throttle:30,1'])->group(function () {
    Route::post('/sessions', [DirectUpiController::class, 'create']);
    Route::get('/sessions/{reference}', [DirectUpiController::class, 'show'])->whereUuid('reference');
});

Route::middleware([
        'auth:sanctum',
        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        'tenant.feature:payments',
        'throttle:20,1',
    ])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->prefix('customer-app/direct-upi')
    ->group(function () {
        Route::post('/sessions', [DirectUpiController::class, 'createPublic'])
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::get('/sessions/{reference}', [DirectUpiController::class, 'showPublic'])->whereUuid('reference');
    });
Route::middleware([
        'auth:sanctum',
        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        'tenant.feature:payments',
        'throttle:20,1',
    ])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->prefix('customer-app/payments')
    ->group(function () {
        Route::post('/manual-requests', [RazorpayCheckoutController::class, 'requestManualPayment'])
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('/razorpay/sessions', [RazorpayCheckoutController::class, 'create'])
            ->middleware(EnsureIdempotentRequest::class.':required');
        Route::post('/razorpay/sessions/{reference}/verify', [RazorpayCheckoutController::class, 'verify'])
            ->whereUuid('reference')->middleware(EnsureIdempotentRequest::class.':required');
    });
Route::controller(PaymentController::class)
    ->prefix('payments')
    ->middleware(['auth', 'compress'])
    ->group(function () {
        // Existing admin routes
        Route::get('/', 'index')->middleware('can:admin.payments.index');

        // NEW: POS-facing payment routes (declared before /{id} so the literal
        // path isn't captured as an id).
        Route::get('/gateways', 'gateways')
            ->middleware('can:admin.orders.receive_payment');

        Route::get('/summary', 'summary')->middleware('can:admin.payments.index');

        Route::get('/{id}', 'show')->middleware('can:admin.payments.show');

        Route::post('/process', 'process')
            ->middleware(['can:admin.orders.receive_payment', EnsureIdempotentRequest::class.':required']);
        Route::post('/refund', 'refund')
            ->middleware(['can:admin.orders.refund', EnsureIdempotentRequest::class.':required']);
        Route::post('/complimentary', 'complimentary')
            ->middleware(['can:admin.orders.complimentary', EnsureIdempotentRequest::class.':required']);
    });
});
