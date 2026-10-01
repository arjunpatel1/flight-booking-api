<?php

use Modules\Saas\Http\Controllers\CustomerAppAssociationController;

Route::get('/.well-known/assetlinks.json', [CustomerAppAssociationController::class, 'android'])
    ->name('customer-app.association.android');
Route::get('/.well-known/apple-app-site-association', [CustomerAppAssociationController::class, 'apple'])
    ->name('customer-app.association.apple');
