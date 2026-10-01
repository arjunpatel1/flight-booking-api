<?php

use Illuminate\Support\Facades\Route;
use Modules\Hotels\Http\Controllers\HotelsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('hotels', HotelsController::class)->names('hotels');
});
