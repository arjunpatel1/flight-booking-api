<?php

use Modules\Import\Http\Controllers\Api\V1\ImportController;

Route::controller(ImportController::class)
    ->prefix('imports')
    ->middleware('tenant.feature:data_imports')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.imports.index');
        Route::get('/meta', 'meta')->middleware('can:admin.imports.index');
        // The client downloads this through the authenticated API client, so
        // stripping auth only left an admin endpoint publicly reachable. Keeping
        // the caller authenticated also lets the sample rows use IDs that exist
        // for that tenant instead of hardcoded placeholders.
        Route::get('/templates/{type}', 'template')->middleware('can:admin.imports.index');
        Route::get('/{id}/failures', 'failures')->middleware('can:admin.imports.show');
        Route::post('/{id}/retry', 'retry')->middleware('can:admin.imports.import');
        Route::delete('/{id}', 'destroy')->middleware('can:admin.imports.import');
        Route::get('/{id}', 'show')->middleware('can:admin.imports.show');
        Route::post('/', 'store')->middleware('can:admin.imports.import');
    });
