<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CanViewDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $device = $request->route('device');

        if (!$device) {
            abort(404, 'Device not found');
        }

        // Use the DevicePolicy's view method
        if (Gate::denies('view', $device)) {
            abort(403, 'You do not have permission to view this device.');
        }

        return $next($request);
    }
}