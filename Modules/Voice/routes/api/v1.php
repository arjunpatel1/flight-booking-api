<?php

use Illuminate\Support\Facades\Route;
use Modules\Voice\Http\Controllers\Api\V1\VoiceAlertController;
use Modules\Voice\Http\Controllers\Api\V1\VoiceAnalyticsController;
use Modules\Voice\Http\Controllers\Api\V1\VoiceController;
use Modules\Voice\Http\Controllers\Api\V1\VoicePerformanceController;

Route::middleware(['auth', 'compress'])->group(function () {
    Route::prefix('voice')->group(function () {
        // Voice Settings
        Route::get('/settings', [VoiceController::class, 'getSettings'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.settings.get');
        Route::post('/settings', [VoiceController::class, 'saveSettings'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.settings.save');

        // Voice Templates
        Route::get('/templates', [VoiceController::class, 'getTemplates'])
            ->middleware('permission:admin.voice.templates.edit')
            ->name('voice.templates.get');
        Route::post('/templates', [VoiceController::class, 'saveTemplate'])
            ->middleware('permission:admin.voice.templates.edit')
            ->name('voice.templates.save');
        Route::delete('/templates/{templateId}', [VoiceController::class, 'deleteTemplate'])
            ->middleware('permission:admin.voice.templates.edit')
            ->name('voice.templates.delete');

        // Voice History
        Route::get('/history', [VoiceController::class, 'getHistory'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.history.get');

        // Voice Testing
        Route::post('/test', [VoiceController::class, 'testVoice'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.test');

        // Voice Announcement Trigger
        Route::post('/trigger', [VoiceController::class, 'triggerAnnouncement'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.trigger');

        // Voice Devices
        Route::get('/devices', [VoiceController::class, 'getDevices'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.devices.get');

        // Bulk Operations
        Route::post('/templates/bulk', [VoiceController::class, 'bulkSaveTemplates'])
            ->middleware('permission:admin.voice.templates.edit')
            ->name('voice.templates.bulk.save');

        Route::delete('/templates/bulk', [VoiceController::class, 'bulkDeleteTemplates'])
            ->middleware('permission:admin.voice.templates.edit')
            ->name('voice.templates.bulk.delete');

        // Performance Monitoring
        Route::get('/performance/metrics', [VoicePerformanceController::class, 'getMetrics'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.performance.metrics');

        Route::post('/performance/cache/clear', [VoicePerformanceController::class, 'clearCache'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.performance.cache.clear');

        Route::post('/performance/cache/warmup', [VoicePerformanceController::class, 'warmupCache'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.performance.cache.warmup');

        // Voice Analytics
        Route::get('/analytics', [VoiceAnalyticsController::class, 'index'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.analytics');

        // Voice Alerts
        Route::get('/alerts', [VoiceAlertController::class, 'index'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.alerts.index');
        Route::get('/alerts/count', [VoiceAlertController::class, 'activeCount'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.alerts.count');
        Route::put('/alerts/{id}/resolve', [VoiceAlertController::class, 'resolve'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.alerts.resolve');
        Route::put('/alerts/{id}/acknowledge', [VoiceAlertController::class, 'acknowledge'])
            ->middleware('permission:admin.voice.settings.edit')
            ->name('voice.alerts.acknowledge');
    });
});
