<?php

use App\Http\Controllers\Device\RestApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('device/{device}/rest-api')
    ->middleware(['auth', 'can.view.device'])
    ->as('device.rest-api.')
    ->group(function () {
        Route::get('/', [RestApiController::class, 'index'])->name('index');
        Route::post('/apply-template', [RestApiController::class, 'applyTemplate'])->name('apply-template');
        Route::delete('/connections/{connection}', [RestApiController::class, 'destroyConnection'])->name('connections.destroy');
    });