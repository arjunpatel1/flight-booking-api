<?php

use Modules\Pos\Http\Controllers\Api\V1\KitchenStationController;
use Modules\Pos\Http\Controllers\Api\V1\KitchenViewerController;
use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use Modules\Pos\Http\Controllers\Api\V1\OfflineModeController;
use Modules\Pos\Http\Controllers\Api\V1\OnboardingSlideController;
use Modules\Pos\Http\Controllers\Api\V1\PosAutomationController;
use Modules\Pos\Http\Controllers\Api\V1\PosCashMovementController;
use Modules\Pos\Http\Controllers\Api\V1\PosManagerApprovalController;
use Modules\Pos\Http\Controllers\Api\V1\PosOwnerCommandCenterController;
use Modules\Pos\Http\Controllers\Api\V1\PosRegisterController;
use Modules\Pos\Http\Controllers\Api\V1\PosRecoveryDashboardController;
use Modules\Pos\Http\Controllers\Api\V1\PosSessionController;
use Modules\Pos\Http\Controllers\Api\V1\PosTerminalDeviceController;
use Modules\Pos\Http\Controllers\Api\V1\PosViewerController;
use Modules\Pos\Http\Controllers\Api\V1\QRCodeController;
use Modules\Pos\Http\Controllers\Api\V1\RecommendationTelemetryController;
use Modules\Pos\Http\Controllers\Api\V1\CustomerDisplaySessionController;

Route::get('pos/customer-display/{cartId}', [CustomerDisplaySessionController::class, 'snapshot'])
    ->whereUuid('cartId')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');

Route::post('pos/customer-display/access', [CustomerDisplaySessionController::class, 'access'])
    ->middleware(['can:admin.pos.index', 'throttle:30,1']);

Route::put('pos/customer-display/{cartId}', [CustomerDisplaySessionController::class, 'publish'])
    ->whereUuid('cartId')
    ->middleware(['can:admin.pos.index', 'throttle:240,1']);

Route::middleware('tenant.feature:pos,pos_registers')->group(function () {
Route::controller(PosRegisterController::class)
    ->prefix('pos/registers')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.pos_registers.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.pos_registers.show|admin.pos_registers.edit');
        Route::post('/', 'store')->middleware('can:admin.pos_registers.create');
        Route::put('/{id}', 'update')->middleware('can:admin.pos_registers.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.pos_registers.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.pos_registers.edit|admin.pos_registers.create');
    });

Route::controller(PosSessionController::class)
    ->prefix('pos/sessions')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.pos_sessions.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.pos_sessions.show');
        Route::post('/open', 'open')->middleware('can:admin.pos_sessions.open');
        Route::put('/{id}/close', 'close')->middleware('can:admin.pos_sessions.close');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.pos_sessions.open');
    });

Route::controller(PosCashMovementController::class)
    ->prefix('pos/cash-movements')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.pos_cash_movements.index');
        Route::post('/', 'store')->middleware('can:admin.pos_cash_movements.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.pos_cash_movements.show');
    });

Route::controller(PosTerminalDeviceController::class)
    ->prefix('pos/terminal-devices')
    ->group(function () {
        Route::get('/', 'index')->middleware(['can:admin.pos_terminal_devices.index', 'throttle:120,1']);
        // Fleet control plane: remotely disable/enable a terminal.
        Route::post('/{id}/disable', 'disable')->whereNumber('id')->middleware(['can:admin.pos_terminal_devices.edit', 'throttle:30,1']);
        Route::post('/{id}/enable', 'enable')->whereNumber('id')->middleware(['can:admin.pos_terminal_devices.edit', 'throttle:30,1']);
    });

Route::post('pos/terminal-devices/heartbeat', [PosTerminalDeviceController::class, 'heartbeat'])
    ->middleware(['can:admin.pos.index', 'throttle:pos-terminal-heartbeat']);

Route::get('pos/recovery-dashboard', [PosRecoveryDashboardController::class, 'show'])
    ->middleware(['can:admin.pos_terminal_devices.index', 'throttle:300,1']);

Route::get('pos/automation', [PosAutomationController::class, 'index'])
    ->middleware(['can:admin.pos.index', 'throttle:120,1']);

// RAE v2 — controlled automation execution (idempotent, auditable, reversible).
Route::get('pos/automation/executions', [PosAutomationController::class, 'executions'])
    ->middleware(['can:admin.pos.index', 'throttle:120,1']);
Route::get('pos/automation/effectiveness', [PosAutomationController::class, 'effectiveness'])
    ->middleware(['can:admin.pos.index', 'throttle:120,1']);
Route::get('pos/automation/owner-center', [PosAutomationController::class, 'ownerCenter'])
    ->middleware(['can:admin.pos.index', 'throttle:120,1']);
Route::get('pos/owner-command-center', [PosOwnerCommandCenterController::class, 'show'])
    ->middleware(['can:admin.pos.index', 'throttle:120,1']);
Route::post('pos/automation/{automation}/execute', [PosAutomationController::class, 'execute'])
    ->middleware(['can:admin.pos.index', EnsureIdempotentRequest::class, 'throttle:60,1']);
Route::post('pos/automation/executions/{execution}/rollback', [PosAutomationController::class, 'rollback'])
    ->whereNumber('execution')
    ->middleware(['can:admin.pos.index', 'throttle:60,1']);

Route::controller(PosManagerApprovalController::class)
    ->prefix('pos/manager-approvals')
    ->middleware(['can:admin.pos.index', 'throttle:60,1'])
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/pin', 'setPin')->middleware('throttle:30,1');
        Route::post('/approve', 'approve')
            ->middleware(EnsureIdempotentRequest::class)
            ->middleware('throttle:30,1');
    });
});


Route::middleware('tenant.feature:kitchen')->group(function () {
Route::controller(KitchenViewerController::class)
    ->prefix('pos/kitchen-viewer')
    ->middleware(['can:admin.pos.kitchen_viewer', 'throttle:120,1'])
    ->group(function () {
        Route::get('configuration', 'configuration');
        Route::get('orders', 'orders');
        Route::patch('{orderId}/move-to-next-status', 'updateOrderProductStatus')
            ->middleware(EnsureIdempotentRequest::class.':required')
            ->middleware('throttle:60,1');
        Route::post('{orderId}/products/cancel', 'cancelOrderProducts')
            ->middleware(EnsureIdempotentRequest::class)
            ->middleware('throttle:30,1');
        Route::post('{orderId}/cancel-delayed', 'cancelDelayedOrder')
            ->middleware(EnsureIdempotentRequest::class)
            ->middleware('throttle:30,1');
    });

Route::controller(KitchenStationController::class)
    ->prefix('pos/kitchen-stations')
    ->middleware(['can:admin.pos.kitchen_stations', 'throttle:60,1'])
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/form/meta', 'getFormMeta');
        Route::put('/{stationId}', 'update');
        Route::delete('/{ids}', 'destroy');
        Route::get('/{stationId}/items', 'getItems')->middleware('throttle:120,1');
        Route::post('/{stationId}/items/{itemId}/start-prep', 'startPrep')->middleware('throttle:60,1');
        Route::post('/{stationId}/items/{itemId}/complete', 'completeItem')->middleware('throttle:60,1');
        Route::post('/{stationId}/items/{itemId}/recall', 'recallItem')->middleware('throttle:60,1');
        Route::post('/{stationId}/items/complete-batch', 'completeItems')->middleware('throttle:60,1');
        Route::patch('/{stationId}/items/{itemId}/priority', 'updatePriority')->middleware('throttle:60,1');
        Route::get('/{stationId}/stats', 'getStats');
        Route::get('/delayed-items', 'getDelayedItems');
        Route::post('/auto-bump', 'autoBump')->middleware('throttle:30,1');
    });
});


// Offline Mode Routes
Route::middleware('tenant.feature:pos,waiter_app')->group(function () {
Route::controller(OfflineModeController::class)
    ->prefix('offline-mode')
    ->middleware('can:admin.pos.index')
    ->group(function () {
        Route::get('/status', 'status');
        Route::post('/enable', 'enable')->middleware('throttle:10,1');
        Route::post('/disable', 'disable')->middleware('throttle:10,1');
        Route::get('/orders', 'orders');
        Route::post('/sync', 'sync')->middleware('throttle:10,1');
        Route::get('/statistics', 'statistics');
        Route::get('/receipt/{orderId}', 'receipt');
        Route::delete('/orders/{orderId}', 'deleteOrder')->middleware('throttle:30,1');
        Route::delete('/clear', 'clear')->middleware('throttle:5,1');
    });

Route::post('offline-mode/orders', [OfflineModeController::class, 'storeOrder'])
    ->middleware(['can:admin.pos.index', 'throttle:pos-offline-order']);

Route::controller(PosViewerController::class)
    ->prefix('pos')
    ->middleware(['can:admin.pos.index', 'throttle:120,1'])
    ->group(function () {
        Route::get('waiter-dashboard', 'waiterDashboardOverview');
        Route::get('waiter-assistant', 'waiterAssistant');
        Route::get('performance-intelligence', 'performanceIntelligence');
        Route::get('revenue-intelligence', 'revenueIntelligence');
    });

// NexDine Intelligence Layer — recommendation telemetry (shown/accepted/ignored)
Route::post('pos/recommendations/telemetry', [RecommendationTelemetryController::class, 'store'])
    ->middleware(['can:admin.pos.index', 'throttle:240,1']);

Route::controller(PosViewerController::class)
    ->prefix('pos/viewer/{cartId}')
    ->whereUuid('cartId')
    ->middleware(['can:admin.pos.index', 'throttle:120,1'])
    ->group(function () {
        Route::get('configuration', 'configuration');
        Route::get('waiter-dashboard', 'waiterDashboard');
        Route::get('menu-items', 'defaultMenuItems');
        Route::get('menu-items/{menuId}', 'menuItems')
            ->whereNumber('menuId');
        Route::get('menu-items/{menuId}/products/{productId}', 'menuProduct')
            ->whereNumber(['menuId', 'productId']);
    });
});

Route::post('qr-order/call-waiter', [QRCodeController::class, 'callWaiter'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([\Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'tenant.feature:qr_ordering', 'throttle:20,1']);

Route::post('qr-order/resolve', [QRCodeController::class, 'resolve'])
    ->withoutMiddleware(['auth', 'auth:sanctum'])
    ->middleware(['tenant.feature:qr_ordering', 'throttle:60,1']);

Route::post('customer-app/qr-order/resolve', [QRCodeController::class, 'resolve'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([\Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'tenant.feature:qr_ordering', 'throttle:60,1']);

// Public: Flutter app fetches active onboarding slides without auth
Route::get('app/onboarding-slides', [OnboardingSlideController::class, 'publicIndex'])
    ->withoutMiddleware(['auth', 'auth:sanctum'])
    ->middleware('throttle:60,1');

// Admin CRUD for onboarding slides
Route::controller(OnboardingSlideController::class)
    ->prefix('pos/onboarding-slides')
    ->middleware(['tenant.feature:pos', 'throttle:60,1'])
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.onboarding_slides.index');
        Route::post('/', 'store')->middleware('can:admin.onboarding_slides.create');
        Route::put('/reorder', 'reorder')->middleware('can:admin.onboarding_slides.edit');
        Route::put('/{id}/toggle-active', 'toggleActive')->middleware('can:admin.onboarding_slides.edit');
        Route::put('/{id}', 'update')->middleware('can:admin.onboarding_slides.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.onboarding_slides.destroy');
    });
