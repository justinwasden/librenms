<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->routes(function () {
            // Existing routes
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Add your REST API routes
            Route::middleware('web')
                ->group(base_path('routes/web/settings/rest-api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web/device/rest-api.php'));
        });
    }
}