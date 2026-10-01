<?php

use Modules\Media\Http\Controllers\Api\V1\MediaController;

Route::controller(MediaController::class)
    ->prefix('media')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.media.index');
        Route::get('/{id}/download', 'download')
            ->whereNumber('id')
            ->middleware('can:admin.media.index')
            ->name('admin.media.download');
        Route::post('/', 'store')
            ->middleware('can:admin.media.create')
            ->middleware('throttle:10,1'); // 10 uploads per minute
        Route::post('/folder/store', 'storeFolder')
            ->middleware('can:admin.media.create')
            ->middleware('throttle:20,1'); // 20 folder creations per minute
        Route::put('/{id}', 'update')->middleware('can:admin.media.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.media.destroy');
    });
