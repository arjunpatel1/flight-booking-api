<?php

use Modules\Aggregator\Http\Controllers\Api\V1\AggregatorIntegrationController;
use Modules\Aggregator\Http\Controllers\Api\V1\AggregatorMenuMappingController;
use Modules\Aggregator\Http\Controllers\Api\V1\AggregatorOutletMappingController;
use Modules\Aggregator\Http\Controllers\Api\V1\AggregatorSyncLogController;
use Modules\Aggregator\Http\Controllers\Api\V1\AggregatorWebhookEventController;
use Modules\Aggregator\Http\Controllers\Api\V1\PartnerCatalogController;
use Modules\Aggregator\Http\Controllers\Api\V1\PartnerIntegrationController;
use Modules\Aggregator\Http\Controllers\Api\V1\PartnerDeliveryController;
use Modules\Aggregator\Http\Controllers\Api\V1\PartnerOrderController;
use Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant;
use Modules\Saas\Http\Middleware\ResolveTenantFromDomain;

Route::prefix('partner')
    ->middleware(['partner.audit', 'partner.auth'])
    ->withoutMiddleware(['auth', 'auth:sanctum', EnsureAuthenticatedTenant::class, ResolveTenantFromDomain::class])
    ->group(function () {
        Route::get('/health', [PartnerCatalogController::class, 'health']);
        Route::get('/branches', [PartnerCatalogController::class, 'branches'])->middleware('partner.scope:catalog:read');
        Route::get('/branches/{branchUuid}/menus', [PartnerCatalogController::class, 'menus'])
            ->whereUuid('branchUuid')->middleware('partner.scope:catalog:read');
        Route::get('/menus/{menuUuid}/products', [PartnerCatalogController::class, 'products'])
            ->whereUuid('menuUuid')->middleware('partner.scope:catalog:read');
        Route::get('/orders', [PartnerOrderController::class, 'index'])->middleware('partner.scope:orders:read');
        Route::post('/orders', [PartnerOrderController::class, 'store'])->middleware(['partner.scope:orders:write', 'partner.idempotency']);
        Route::get('/orders/{orderId}', [PartnerOrderController::class, 'show'])->whereUuid('orderId')->middleware('partner.scope:orders:read');
        Route::post('/orders/{orderId}/cancel', [PartnerOrderController::class, 'cancel'])->whereUuid('orderId')->middleware(['partner.scope:orders:write', 'partner.idempotency']);
        Route::post('/orders/{orderId}/status', [PartnerOrderController::class, 'updateStatus'])->whereUuid('orderId')->middleware(['partner.scope:orders:status', 'partner.idempotency']);
        Route::post('/orders/{orderId}/payments', [PartnerOrderController::class, 'collectPayment'])->whereUuid('orderId')->middleware(['partner.scope:payments:write', 'partner.idempotency']);
        Route::get('/deliveries/{orderId}', [PartnerDeliveryController::class, 'show'])->whereUuid('orderId')->middleware('partner.scope:deliveries:read');
        Route::post('/deliveries/{orderId}/status', [PartnerDeliveryController::class, 'updateStatus'])->whereUuid('orderId')->middleware(['partner.scope:deliveries:status', 'partner.idempotency']);
    });

Route::middleware('tenant.feature:aggregator')->group(function () {
    Route::controller(PartnerIntegrationController::class)
        ->prefix('partner-integrations')
        ->group(function () {
            Route::get('/meta', 'meta')->middleware('can:admin.partner_integrations.index');
            Route::get('/', 'index')->middleware('can:admin.partner_integrations.index');
            Route::post('/', 'store')->middleware(['can:admin.partner_integrations.create', 'throttle:10,1']);
            Route::post('/credentials/{credentialUuid}/rotate', 'rotate')->whereUuid('credentialUuid')->middleware(['can:admin.partner_integrations.edit', 'throttle:5,1']);
            Route::put('/credentials/{credentialUuid}', 'update')->whereUuid('credentialUuid')->middleware(['can:admin.partner_integrations.edit', 'throttle:20,1']);
            Route::post('/credentials/{credentialUuid}/revoke', 'revoke')->whereUuid('credentialUuid')->middleware(['can:admin.partner_integrations.destroy', 'throttle:10,1']);
        });

    Route::controller(AggregatorIntegrationController::class)
        ->prefix('aggregator-integrations')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.aggregator_integrations.index');
            Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.aggregator_integrations.create|admin.aggregator_integrations.edit');
            Route::get('/{id}/provider-status', 'providerStatus')->middleware('permission:admin.aggregator_integrations.show|admin.aggregator_integrations.logs');
            Route::get('/{id}', 'show')->middleware('permission:admin.aggregator_integrations.show|admin.aggregator_integrations.edit');
            Route::post('/', 'store')->middleware('can:admin.aggregator_integrations.create');
            Route::post('/{id}/sync', 'sync')->middleware('can:admin.aggregator_integrations.sync');
            Route::post('/{id}/item-availability', 'itemAvailability')->middleware('can:admin.aggregator_integrations.sync');
            Route::post('/{id}/test-connection', 'testConnection')->middleware('can:admin.aggregator_integrations.sync');
            Route::patch('/{id}/toggle', 'toggle')->middleware('can:admin.aggregator_integrations.edit');
            Route::put('/{id}', 'update')->middleware('can:admin.aggregator_integrations.edit');
            Route::delete('/{ids}', 'destroy')->middleware('can:admin.aggregator_integrations.destroy');
        });

    Route::controller(AggregatorOutletMappingController::class)
        ->prefix('aggregator-outlet-mappings')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.aggregator_integrations.index');
            Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.aggregator_integrations.create|admin.aggregator_integrations.edit');
            Route::get('/{id}', 'show')->middleware('permission:admin.aggregator_integrations.show|admin.aggregator_integrations.edit');
            Route::post('/', 'store')->middleware('can:admin.aggregator_integrations.create');
            Route::put('/{id}', 'update')->middleware('can:admin.aggregator_integrations.edit');
            Route::delete('/{ids}', 'destroy')->middleware('can:admin.aggregator_integrations.destroy');
        });

    Route::controller(AggregatorMenuMappingController::class)
        ->prefix('aggregator-menu-mappings')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.aggregator_integrations.index');
            Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.aggregator_integrations.create|admin.aggregator_integrations.edit');
            Route::get('/{id}', 'show')->middleware('permission:admin.aggregator_integrations.show|admin.aggregator_integrations.edit');
            Route::post('/', 'store')->middleware('can:admin.aggregator_integrations.create');
            Route::put('/{id}', 'update')->middleware('can:admin.aggregator_integrations.edit');
            Route::delete('/{ids}', 'destroy')->middleware('can:admin.aggregator_integrations.destroy');
        });

    Route::controller(AggregatorSyncLogController::class)
        ->prefix('aggregator-sync-logs')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.aggregator_integrations.logs');
            Route::post('/{id}/retry', 'retry')->middleware('can:admin.aggregator_integrations.sync');
            Route::get('/{id}', 'show')->middleware('can:admin.aggregator_integrations.logs');
        });

    Route::controller(AggregatorWebhookEventController::class)
        ->prefix('aggregator-webhook-events')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.aggregator_integrations.logs');
            Route::get('/stats', 'stats')->middleware('can:admin.aggregator_integrations.logs');
            Route::post('/{id}/reprocess', 'reprocess')->middleware('can:admin.aggregator_integrations.sync');
            Route::get('/{id}', 'show')->middleware('can:admin.aggregator_integrations.logs');
            Route::post('/{integrationId}/receive', 'receive')->withoutMiddleware('auth');
        });
});
