<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RestApiTemplateController;
use App\Http\Controllers\RestApiCredentialController;
use App\Http\Controllers\RestApiMappingController;

Route::middleware(['auth', 'admin'])->group(function () {
    // Templates
    Route::resource('rest-api.templates', RestApiTemplateController::class)->only([
        'index', 'show', 'edit', 'update'
    ]);

    Route::get('rest-api/templates/{template}/devices', [RestApiTemplateController::class, 'devices'])
        ->name('rest-api.templates.devices');

    // Credentials
    Route::get('devices/{device}/rest-api/create', [RestApiCredentialController::class, 'create'])
        ->name('rest-api.credentials.create');

    Route::post('devices/{device}/rest-api', [RestApiCredentialController::class, 'store'])
        ->name('rest-api.credentials.store');

    Route::get('devices/{device}/rest-api/edit', [RestApiCredentialController::class, 'edit'])
        ->name('rest-api.credentials.edit');

    Route::put('devices/{device}/rest-api', [RestApiCredentialController::class, 'update'])
        ->name('rest-api.credentials.update');

    Route::post('devices/{device}/rest-api/mapping', [RestApiCredentialController::class, 'setMapping'])
        ->name('rest-api.credentials.set-mapping');

    Route::delete('devices/{device}/rest-api', [RestApiCredentialController::class, 'destroy'])
        ->name('rest-api.credentials.destroy');

    Route::post('devices/{device}/rest-api/test', [RestApiCredentialController::class, 'test'])
        ->name('rest-api.credentials.test');

    // Custom Mappings
    Route::get('rest-api/mappings', [RestApiMappingController::class, 'list'])
        ->name('rest-api.mappings.list');

    Route::get('rest-api/mappings/create', [RestApiMappingController::class, 'create'])
        ->name('rest-api.mappings.create');

    Route::post('rest-api/mappings', [RestApiMappingController::class, 'store'])
        ->name('rest-api.mappings.store');

    Route::get('rest-api/mappings/{name}/edit', [RestApiMappingController::class, 'edit'])
        ->name('rest-api.mappings.edit');

    Route::put('rest-api/mappings/{name}', [RestApiMappingController::class, 'update'])
        ->name('rest-api.mappings.update');

    Route::delete('rest-api/mappings/{name}', [RestApiMappingController::class, 'destroy'])
        ->name('rest-api.mappings.destroy');
});
