<?php

use Modules\Order\Http\Controllers\Api\V1\DeliveryCancellationController;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletController;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletFundingController;
use Modules\Order\Http\Controllers\Api\V1\ManualDeliveryDispatchController;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Http\Controllers\Api\V1\DeliveryLocationController;
use Modules\Setting\Http\Controllers\Api\V1\SettingController;
use Modules\Setting\Http\Controllers\Api\V1\SystemBackupController;
use Modules\Setting\Http\Controllers\Api\V1\SystemMaintenanceController;

Route::controller(SystemBackupController::class)
    ->prefix('system-backups')
    ->middleware('can:admin.system_configurations.edit')
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/restores', 'restores');
        Route::post('/{systemBackup}/restore', 'restore');
    });

Route::post('system-maintenance/{action}', [SystemMaintenanceController::class, 'run'])
    ->whereIn('action', ['clear_cache', 'repair_permissions', 'restart_workers', 'printer_recovery', 'full_recovery'])
    ->middleware('can:admin.system_configurations.edit');

Route::controller(SystemMaintenanceController::class)
    ->prefix('system-maintenance')
    ->middleware('can:admin.system_configurations.edit')
    ->group(function () {
        Route::get('health', 'health');
        Route::get('logs', 'logs');
        Route::get('logs/download', 'downloadLogs');
        Route::post('logs/clear', 'clearLogs');
    });

Route::controller(SettingController::class)
    ->group(function () {
        Route::patch('settings/whatsapp/alerts', 'updateWhatsAppAlerts')
            ->middleware(['can:admin.settings.edit', 'throttle:10,1']);
        Route::get('app/settings', 'getAppSettings')->withoutMiddleware(['auth', 'auth:sanctum']);
        Route::get('app/boot-data', 'bootData')->withoutMiddleware(['auth', 'auth:sanctum']);
        // Tenant administrators may invalidate only their frontend/boot cache.
        // Infrastructure-wide maintenance remains protected above.
        Route::post('app/clear-boot-cache', 'clearBootCache')->middleware('can:admin.settings.edit');
        Route::prefix('settings/appearance')
            ->middleware(['can:admin.appearance.edit'])
            ->group(function () {
                Route::get('/', 'appearance');
                Route::put('/update', 'updateAppearance');
            });
        Route::prefix('settings/system_configuration')
            ->middleware(['can:admin.system_configurations.edit'])
            ->group(function () {
                Route::get('/', 'systemConfiguration');
                Route::put('/update', 'updateSystemConfiguration');
            });
        Route::get('settings/firebase/health', 'firebaseHealth')
            ->middleware(['can:admin.settings.edit']);
        Route::prefix('settings/delivery')
            ->middleware(['can:admin.delivery_settings.edit', 'tenant.feature:delivery'])
            ->group(function () {
                Route::get('/', fn () => app(SettingController::class)->index(SettingSection::Delivery));
                Route::put('/update', fn (\Modules\Setting\Http\Requests\Api\V1\SaveSettingRequest $request) => app(SettingController::class)->update($request, SettingSection::Delivery));
                Route::get('/wallet', [DeliveryWalletController::class, 'show']);
                Route::put('/wallet', [DeliveryWalletController::class, 'update'])->middleware('throttle:10,1');
                Route::get('/wallet/funding', [DeliveryWalletFundingController::class, 'tenantIndex']);
                Route::post('/wallet/top-up-requests', [DeliveryWalletFundingController::class, 'requestManual'])->middleware('throttle:10,1');
                Route::post('/wallet/top-up-gateway', [DeliveryWalletFundingController::class, 'startGateway'])->middleware('throttle:10,1');
                Route::post('/wallet/top-up-gateway/{uuid}/verify', [DeliveryWalletFundingController::class, 'verifyGateway'])->whereUuid('uuid')->middleware('throttle:20,1');
                Route::post('/wallet/top-up-gateway/{uuid}/cancel', [DeliveryWalletFundingController::class, 'cancelGateway'])->whereUuid('uuid')->middleware('throttle:20,1');
                Route::get('/locations', [DeliveryLocationController::class, 'index']);
                Route::get('/locations/{branch}/history', [DeliveryLocationController::class, 'history']);
                Route::put('/locations/{branch}', [DeliveryLocationController::class, 'update'])->middleware('throttle:20,1');
                Route::get('/location-search', [DeliveryLocationController::class, 'search'])->middleware('throttle:20,1');
                Route::post('/serviceability', [DeliveryLocationController::class, 'serviceability'])->middleware('throttle:20,1');
                Route::get('/orders/{order}/manual-dispatch', [ManualDeliveryDispatchController::class, 'preview'])->middleware('can:admin.orders.show');
                Route::post('/orders/{order}/manual-dispatch', [ManualDeliveryDispatchController::class, 'send'])->middleware(['can:admin.orders.edit', 'throttle:5,1']);
                Route::get('/orders/{order}/cancel-delivery', [DeliveryCancellationController::class, 'preview'])->middleware('can:admin.orders.show');
                Route::post('/orders/{order}/cancel-delivery', [DeliveryCancellationController::class, 'cancel'])->middleware(['can:admin.orders.cancel', 'throttle:5,1']);
            });
        Route::prefix('settings/{section}')
            ->whereIn(
                'section',
                array_diff(SettingSection::values(), [SettingSection::SystemConfiguration->value, SettingSection::Delivery->value])
            )
            ->middleware(['can:admin.settings.edit'])
            ->group(function () {
                Route::get('/', 'index');
                Route::put('/update', 'update');
            });
    });
