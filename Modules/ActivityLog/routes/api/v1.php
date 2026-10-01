<?php

use Modules\ActivityLog\Http\Controllers\Api\V1\ActivityLogController;
use Modules\ActivityLog\Http\Controllers\Api\V1\AuthenticationLogController;

Route::controller(ActivityLogController::class)
    ->prefix('activity-logs')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.activity_logs.index');
        Route::delete('/', 'destroy')->middleware(['can:admin.activity_logs.destroy', 'throttle:5,1']);
        Route::get('/{id}', 'show')->middleware('can:admin.activity_logs.show');
    });

Route::controller(AuthenticationLogController::class)
    ->prefix('authentication-logs')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.authentication_logs.index');
        Route::get('/{id}', 'show')->middleware('can:admin.authentication_logs.show');
    });
