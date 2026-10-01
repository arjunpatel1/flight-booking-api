<?php

use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppCenterController;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppInsightsController;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppOrderingController;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppReportShareController;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppScheduleController;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppTemplateController;

// Stable provider-level callbacks. Meta permits one callback URL per app, so
// tenant context is resolved from the signed provider phone identifier.
Route::get('whatsapp/webhook/meta', [WhatsAppOrderingController::class, 'verifyProviderWebhook'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])->middleware('throttle:60,1');
Route::post('whatsapp/webhook/{provider}', [WhatsAppOrderingController::class, 'providerWebhook'])
    ->whereIn('provider', ['meta', 'msg91', 'nexmsg'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])->middleware('throttle:240,1');

// Backward-compatible opaque profile callbacks. Existing integrations can be
// migrated without downtime; new integrations should use provider callbacks.
Route::get('whatsapp-ordering/webhook/{profile}', [WhatsAppOrderingController::class, 'verifyWebhook'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])->middleware('throttle:60,1');
Route::post('whatsapp-ordering/webhook/{profile}', [WhatsAppOrderingController::class, 'webhook'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])->middleware('throttle:240,1');

Route::middleware('tenant.feature:whatsapp')->group(function () {
    Route::prefix('whatsapp-center')->group(function () {
        Route::get('dashboard', [WhatsAppCenterController::class, 'dashboard'])
            ->middleware('can:admin.whatsapp_center.index');

        Route::get('templates', [WhatsAppTemplateController::class, 'index'])
            ->middleware('can:admin.whatsapp_center.templates');
        Route::post('templates', [WhatsAppTemplateController::class, 'store'])
            ->middleware('can:admin.whatsapp_center.templates');
        Route::put('templates/{template}', [WhatsAppTemplateController::class, 'update'])
            ->middleware('can:admin.whatsapp_center.templates');
        Route::delete('templates/{template}', [WhatsAppTemplateController::class, 'destroy'])
            ->middleware('can:admin.whatsapp_center.templates');

        Route::get('schedules', [WhatsAppScheduleController::class, 'index'])
            ->middleware('can:admin.whatsapp_center.schedule');
        Route::post('schedules', [WhatsAppScheduleController::class, 'store'])
            ->middleware('can:admin.whatsapp_center.schedule');
        Route::put('schedules/{schedule}', [WhatsAppScheduleController::class, 'update'])
            ->middleware('can:admin.whatsapp_center.schedule');
        Route::delete('schedules/{schedule}', [WhatsAppScheduleController::class, 'destroy'])
            ->middleware('can:admin.whatsapp_center.schedule');
        Route::post('schedules/{schedule}/toggle', [WhatsAppScheduleController::class, 'toggle'])
            ->middleware('can:admin.whatsapp_center.schedule');
        Route::post('schedules/{schedule}/run-now', [WhatsAppScheduleController::class, 'runNow'])
            ->middleware('can:admin.whatsapp_center.schedule');

        Route::get('report-types', [WhatsAppReportShareController::class, 'reportTypes'])
            ->middleware('can:admin.whatsapp_center.send');
        Route::post('share-report', [WhatsAppReportShareController::class, 'share'])
            ->middleware('can:admin.whatsapp_center.send');

        Route::get('insights', [WhatsAppInsightsController::class, 'index'])
            ->middleware('can:admin.whatsapp_center.index');

        Route::get('logs', [\Modules\Notification\Http\Controllers\Api\V1\WhatsAppLogController::class, 'index'])
            ->middleware('can:admin.whatsapp_center.logs');
    });
});

Route::prefix('whatsapp-ordering')
    ->middleware(['tenant.feature:whatsapp_ordering', 'permission:admin.whatsapp_ordering.index|admin.whatsapp_center.index'])
    ->group(function () {
        Route::get('/', [WhatsAppOrderingController::class, 'overview']);
        Route::get('/diagnostics', [WhatsAppOrderingController::class, 'diagnostics'])->middleware('throttle:6,1');
        Route::post('/connection/restaurant-owned', [WhatsAppOrderingController::class, 'connect'])
            ->middleware(['tenant.feature:whatsapp_bring_your_own_api', 'permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:10,1']);
        Route::delete('/connection', [WhatsAppOrderingController::class, 'disconnect'])
            ->middleware(['permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:5,1']);
        Route::post('/connection/webhook-secret/rotate', [WhatsAppOrderingController::class, 'rotateWebhookSecret'])
            ->middleware(['tenant.feature:whatsapp_bring_your_own_api', 'permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:5,1']);
        Route::put('/configuration', [WhatsAppOrderingController::class, 'configure'])
            ->middleware('permission:admin.whatsapp_ordering.edit|admin.settings.edit');
        Route::post('/catalog/mappings/sync', [WhatsAppOrderingController::class, 'syncCatalogMappings'])
            ->middleware(['permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:10,1']);
        Route::get('/catalog/mappings', [WhatsAppOrderingController::class, 'catalogMappings']);
        Route::put('/catalog/mappings/{productUuid}', [WhatsAppOrderingController::class, 'saveCatalogMapping'])
            ->whereUuid('productUuid')->middleware(['permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:60,1']);
        Route::get('/conversations', [WhatsAppOrderingController::class, 'conversations']);
        Route::get('/webhook-events', [WhatsAppOrderingController::class, 'webhookEvents']);
        Route::delete('/webhook-events', [WhatsAppOrderingController::class, 'clearWebhookEvents'])
            ->middleware(['permission:admin.whatsapp_ordering.edit|admin.settings.edit', 'throttle:5,1']);
        Route::get('/conversations/{uuid}', [WhatsAppOrderingController::class, 'conversation'])->whereUuid('uuid');
        Route::patch('/conversations/{uuid}/handoff', [WhatsAppOrderingController::class, 'handoff'])
            ->whereUuid('uuid')->middleware(['tenant.feature:whatsapp_human_handoff', 'permission:admin.whatsapp_ordering.edit|admin.whatsapp_center.index']);
        Route::get('/order-sessions', [WhatsAppOrderingController::class, 'orderSessions']);
        Route::put('/order-sessions/{uuid}/delivery-address', [WhatsAppOrderingController::class, 'deliveryAddress'])
            ->whereUuid('uuid')->middleware('permission:admin.whatsapp_ordering.accept|admin.whatsapp_center.index');
        Route::post('/order-sessions/{uuid}/approve', [WhatsAppOrderingController::class, 'approve'])
            ->whereUuid('uuid')->middleware('permission:admin.whatsapp_ordering.accept|admin.whatsapp_center.index');
        Route::post('/order-sessions/{uuid}/payment', [WhatsAppOrderingController::class, 'payment'])
            ->whereUuid('uuid')->middleware(['tenant.feature:whatsapp_order_payments', 'permission:admin.whatsapp_ordering.receive_payment|admin.whatsapp_center.index']);
    });
