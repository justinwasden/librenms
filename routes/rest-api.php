<?php

use App\Http\Controllers\DeviceRestApiController;
use App\RestApi\Services\MapperSelectionService;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Device REST API Settings
    Route::get('devices/{device}/rest-api', [DeviceRestApiController::class, 'edit'])
        ->name('devices.rest-api.edit');
    
    Route::put('devices/{device}/rest-api', [DeviceRestApiController::class, 'update'])
        ->name('devices.rest-api.update');
    
    Route::post('devices/{device}/rest-api/test', [DeviceRestApiController::class, 'test'])
        ->name('devices.rest-api.test');

    // API Endpoints for AJAX
    Route::prefix('api/rest-api')->group(function () {
        Route::get('mappers', function () {
            return MapperSelectionService::getAvailableMappers();
        })->name('api.rest-api.mappers');

        Route::get('mappers/{mapper}/endpoints', function ($mapper) {
            return MapperSelectionService::getMapperEndpoints(urldecode($mapper));
        })->name('api.rest-api.mapper-endpoints');

        Route::get('mappers/{mapper}/endpoints/{endpoint}', function ($mapper, $endpoint) {
            return MapperSelectionService::getEndpointMappings(
                urldecode($mapper),
                urldecode($endpoint)
            );
        })->name('api.rest-api.endpoint-mappings');
    });
});
