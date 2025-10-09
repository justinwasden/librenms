<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RestApiEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RestApiEndpointController extends Controller
{
    /**
     * Update an endpoint (global management)
     */
    public function update(Request $request, RestApiEndpoint $endpoint)
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'path' => 'required|string|max:2048',
            'method' => 'required|in:GET,POST,PUT,DELETE',
            'resource_type' => 'nullable|string|max:50',
            'metric_map' => 'nullable|array',
        ]);

        $endpoint->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Endpoint updated successfully',
            'endpoint' => $endpoint
        ]);
    }

    /**
     * Delete an endpoint (global management)
     */
    public function destroy(RestApiEndpoint $endpoint)
    {
        Gate::authorize('admin');

        $endpoint->delete();

        return response()->json([
            'success' => true,
            'message' => 'Endpoint deleted successfully'
        ]);
    }
}
