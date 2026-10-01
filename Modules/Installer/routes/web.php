<?php

use App\NexDine;
use Illuminate\Support\Facades\Route;
use Modules\Installer\Http\Controllers\InstallerController;
use Modules\Installer\Http\Middleware\CheckInstallation;

Route::controller(InstallerController::class)
    ->prefix('installer')
    ->name('installer.')
    ->middleware(CheckInstallation::class)
    ->withoutMiddleware("checkInstalled")
    ->group(function () {
        Route::get('/', 'welcome')->name('welcome');
        Route::get('/permissions', 'permissions')->name('permissions');
        Route::get('/requirements', 'requirements')->name('requirements');
        Route::get('/database', 'database')->name('database');
        Route::post('/database', 'database')->name('database.store');
        Route::get('/admin', 'admin')->name('admin');
        Route::post('/admin', 'admin')->name('admin.store');
        Route::get('/finish', 'finish')->name('finish');
    });

if (!NexDine::installed()) {
    Route::fallback(fn() => abort(404));
}
