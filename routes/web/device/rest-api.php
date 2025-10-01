<?php

use App\Http\Controllers\Device\RestApiActionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('device/{device}/rest-api')
    ->middleware(['auth', 'can.view.device'])
    ->as('device.rest-api.')
    ->group(function () {
        Route::get('/', [RestApiActionsController::class, 'index'])->name('index');
        Route::post('/apply-template', [RestApiActionsController::class, 'applyTemplate'])->name('apply-template');
        Route::delete('/connections/{connection}', [RestApiActionsController::class, 'destroyConnection'])->name('connections.destroy');
    });