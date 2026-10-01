<?php

use Modules\Translation\Http\Controllers\Api\V1\TranslationController;

Route::controller(TranslationController::class)
    ->prefix("translations")
    ->group(function () {
        Route::get("/", "index")->withoutMiddleware(['auth', 'auth:sanctum']);
        Route::put("/{key}", "update")->middleware('can:admin.translations.edit');
    });

Route::get("/app/translations", [TranslationController::class, "getAppTranslations"])
    ->withoutMiddleware(['auth', 'auth:sanctum']);
