<?php

use App\Http\Controllers\Settings\RestApiCredentialController;
use App\Http\Controllers\Settings\RestApiTemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings/rest-api')
    ->middleware(['auth', 'can:admin'])
    ->as('settings.rest-api.')
    ->group(function () {
        Route::get('credentials/types/{typeId}/params', [RestApiCredentialController::class, 'getAuthTypeParams'])
    ->name('credentials.params');
				Route::resource('credentials', RestApiCredentialController::class);
        Route::resource('templates', RestApiTemplateController::class);
    });