<?php

use Modules\SeatingPlan\Http\Controllers\Api\V1\FloorController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\PublicReservationController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\ReservationController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\TableController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\TableMergeController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\TableViewerController;
use Modules\SeatingPlan\Http\Controllers\Api\V1\ZoneController;

Route::controller(PublicReservationController::class)
    ->prefix('customer-reservations')
    ->withoutMiddleware(['auth', 'auth:sanctum'])
    ->middleware(['tenant.feature:reservations', 'throttle:30,1'])
    ->group(function () {
        Route::post('/', 'store');
        Route::get('/{reference}', 'show')->where('reference', 'RSV-[A-Z0-9]{10}');
        Route::post('/{reference}/cancel', 'cancel')->where('reference', 'RSV-[A-Z0-9]{10}');
    });

Route::controller(PublicReservationController::class)
    ->prefix('customer-app/reservations')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware(['auth:sanctum', \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'tenant.feature:reservations', 'throttle:30,1'])
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{reference}', 'show')->where('reference', 'RSV-[A-Z0-9]{10}');
        Route::put('/{reference}', 'update')->where('reference', 'RSV-[A-Z0-9]{10}');
        Route::post('/{reference}/cancel', 'cancel')->where('reference', 'RSV-[A-Z0-9]{10}');
    });

Route::middleware('tenant.feature:pos,reservations,waiter_app')->group(function () {
Route::controller(FloorController::class)
    ->prefix('floors')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.floors.index');
        Route::get('/{id}/planner-snapshot', 'plannerSnapshot')->middleware('can:admin.tables.viewer');
        Route::put('/{id}/planner-snapshot', 'updatePlannerSnapshot')->middleware('can:admin.tables.edit');
        Route::get('/{id}', 'show')->middleware('permission:admin.floors.show|admin.floors.edit');
        Route::post('/', 'store')->middleware('can:admin.floors.create');
        Route::put('/{id}', 'update')->middleware('can:admin.floors.edit');
        Route::patch('/{id}/map-settings', 'updateMapSettings')->middleware('can:admin.tables.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.floors.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.floors.edit|admin.floors.create');
    });

Route::controller(TableMergeController::class)
    ->prefix('table-merges')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.table_merges.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.table_merges.show');
    });

Route::controller(ZoneController::class)
    ->prefix('zones')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.zones.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.zones.show|admin.zones.edit');
        Route::post('/', 'store')->middleware('can:admin.zones.create');
        Route::put('/{id}', 'update')->middleware('can:admin.zones.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.zones.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.zones.edit|admin.zones.create');
    });

Route::controller(TableController::class)
    ->prefix('tables')
    ->group(function () {
        Route::get('/{id}/reservations', [ReservationController::class, 'byTable'])->middleware('can:admin.reservations.index');
        Route::get('/', 'index')->middleware('can:admin.tables.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.tables.show|admin.tables.edit');
        Route::get('/{id}/status-logs', 'getStatusLogs')->middleware('can:admin.tables.show');
        Route::post('/', 'store')->middleware('can:admin.tables.create');
        Route::put('/{id}', 'update')->middleware('can:admin.tables.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.tables.destroy');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.tables.edit|admin.tables.create');
    });

Route::controller(ReservationController::class)
    ->prefix('reservations')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.reservations.index');
        Route::get('/upcoming', 'upcoming')->middleware('can:admin.reservations.index');
        Route::get('/meta', 'meta')->middleware('permission:admin.reservations.index|admin.reservations.create|admin.reservations.edit');
        Route::post('/', 'store')->middleware('can:admin.reservations.create');
        Route::put('/{id}', 'update')->middleware('can:admin.reservations.edit');
        Route::post('/{id}/confirm', 'confirm')->middleware('can:admin.reservations.edit');
        Route::post('/{id}/seat', 'seat')->middleware('can:admin.reservations.edit');
        Route::post('/{id}/cancel', 'cancel')->middleware('can:admin.reservations.edit');
    });

Route::post('tables/positions', [TableViewerController::class, 'savePositions'])->middleware('can:admin.tables.edit');

Route::controller(TableViewerController::class)
    ->prefix('tables/viewer')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.tables.viewer');
        Route::get('/{id}', 'show')->middleware('can:admin.tables.show');
        Route::patch('/{id}/assign-waiter', 'assignWaiter')->middleware('can:admin.tables.assign_waiter');
        Route::post('/{id}/merge', 'merge')->middleware('can:admin.tables.merge');
        Route::patch('/{id}/transfer', 'transfer')->middleware('can:admin.tables.transfer');
        Route::get('/{id}/merge/meta', 'getMergeMeta')->middleware('can:admin.tables.merge');
        Route::patch('/{id}/make-available', 'makeAsAvailable')->middleware('can:admin.tables.update_status');
        Route::post('/{id}/split', 'splitTable')->middleware('can:admin.tables.split');
    });
});
