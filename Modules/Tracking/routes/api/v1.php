<?php

use Modules\Tracking\Http\Controllers\Api\V1\LiveTrackingController;

Route::middleware('tenant.feature:delivery')->group(function () {
Route::controller(LiveTrackingController::class)
    ->prefix('live-tracking')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.live_tracking.index');
        // The navbar counter is also useful to order operators. The controller
        // performs an explicit any-of permission check while tenant scoping
        // remains enforced by the service.
        Route::get('/pending-count', 'pendingCount');
        Route::get('/reject/meta', 'rejectMeta')->middleware('can:admin.live_orders.reject');
        Route::get('/{trackingId}', 'show')->middleware('can:admin.live_tracking.show');
        Route::patch('/{trackingId}/accept', 'accept')->middleware('can:admin.live_orders.accept');
        Route::post('/{trackingId}/reject', 'reject')->middleware('can:admin.live_orders.reject');
    });
});
