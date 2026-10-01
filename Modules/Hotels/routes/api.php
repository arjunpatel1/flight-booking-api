<?php

use Illuminate\Support\Facades\Route;
use Modules\Hotels\Http\Controllers\HotelsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('hotels', HotelsController::class)->names('hotels');
});
