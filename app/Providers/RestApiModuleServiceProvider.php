<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Discovery\RestApiDiscovery;
use App\Pollers\RestApiPoller;

class RestApiModuleServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register your discovery and poller classes in the container
        $this->app->bind('rest-api-discovery', fn() => new RestApiDiscovery());
        $this->app->bind('rest-api-poller', fn() => new RestApiPoller());
    }

    public function boot()
    {
        // Optionally hook into discovery/poller events if needed
    }
}
