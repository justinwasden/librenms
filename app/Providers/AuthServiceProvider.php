<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\User;
use App\Policies\DevicePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        Device::class => DevicePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Define admin gate
        Gate::define('admin', function (User $user) {
            return $user->isAdmin();
        });

        // You can also define global admin check
        Gate::before(function (User $user, $ability) {
            // Admins can do everything
            if ($user->isAdmin()) {
                return true;
            }
        });
    }
}