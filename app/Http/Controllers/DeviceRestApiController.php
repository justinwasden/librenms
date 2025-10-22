<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Global Device REST API Controller
 * Handles device-level REST API configuration at the admin level
 * (Note: Most device REST API management is now at device.edit.rest-api)
 */
class DeviceRestApiController extends Controller
{
    /**
     * Show device REST API configuration
     */
    public function show(Device $device)
    {
        Gate::authorize('admin');
        
        // Redirect to the device-level REST API edit page
        return redirect()->route('device.edit.rest-api', $device);
    }

    /**
     * Update device REST API configuration
     */
    public function update(Request $request, Device $device)
    {
        Gate::authorize('admin');
        
        // This is a placeholder - most updates are handled at the device level
        // via the Device\RestApiController
        return redirect()->route('device.edit.rest-api', $device)->with('info', 'Use the device-level REST API settings to configure.');
    }

    /**
     * Test device REST API connection
     */
    public function test(Request $request, Device $device)
    {
        Gate::authorize('admin');
        
        // TODO: Implement REST API connection testing
        return response()->json([
            'status' => 'error',
            'message' => 'REST API testing not yet implemented at global level. Use device-level settings.'
        ], 501);
    }
}
