<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RestApiController extends Controller
{
    public function edit(Device $device)
    {
        Gate::authorize('update', $device);

        // Load credentials as well, needed for the credential selection modal
        $credentials = \App\Models\RestApiCredential::all();
        $device->load('restApiConnections.endpoints', 'restApiConnections.credential');
        $templates = RestApiTemplate::all();

        return view('device.edit.rest-api', compact('device', 'templates', 'credentials'));
    }

    public function applyTemplate(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $request->validate(['template_id' => 'required|exists:rest_api_templates,id']);

        $template = RestApiTemplate::find($request->template_id);

        $templateData = $this->replacePlaceholdersInArray($template->template_data, $device);

        foreach ($templateData['connections'] as $connData) {
            $connection = $device->restApiConnections()->create([
                'name' => $connData['name'],
                'base_url' => $connData['base_url'],
                'credential_id' => $connData['credential_id'] ?? null,
                'rate_limit' => $connData['rate_limit'] ?? 60,
                'enabled' => $connData['enabled'] ?? true, // Default to enabled
                'disable_ssl_verify' => $connData['disable_ssl_verify'] ?? false, // Default to false
            ]);

            if (isset($connData['endpoints']) && is_array($connData['endpoints'])) {
                foreach ($connData['endpoints'] as $endpointData) {
                    $connection->endpoints()->create($endpointData);
                }
            }
        }

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Template applied successfully.');
    }

    public function storeConnection(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:2048',
            'rate_limit' => 'nullable|integer|min:1', // FIX: Set to nullable
        ]);

        $device->restApiConnections()->create(array_merge($validated, [
            'enabled' => true,
            'disable_ssl_verify' => false,
            'rate_limit' => $validated['rate_limit'] ?? 60,
        ]));

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Custom connection created successfully.');
    }

    public function updateConnection(Request $request, Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        // Ensure the connection belongs to this device
        if ($connection->device_id !== $device->device_id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:2048',
            'rate_limit' => 'nullable|integer|min:1', // FIX: Set to nullable
        ]);

        // Handle boolean fields from the modal checkboxes
        $validated['enabled'] = $request->has('enabled');
        $validated['disable_ssl_verify'] = $request->has('disable_ssl_verify');


        $connection->update($validated);

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Connection updated successfully.');
    }

    public function updateConnectionCredential(Request $request, Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        if ($connection->device_id !== $device->device_id) {
            abort(404);
        }

        $validated = $request->validate([
            'credential_id' => 'nullable|exists:rest_api_credentials,id',
        ]);

        // FIX: The update logic was here, using $validated
        $connection->update($validated);

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Credentials applied successfully.');
    }

    public function destroyConnection(Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        // Ensure the connection belongs to this device
        if ($connection->device_id !== $device->device_id) {
            abort(404);
        }

        $connection->delete();

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'API Connection deleted successfully.');
    }

    public function updateEndpoint(Request $request, Device $device, RestApiEndpoint $endpoint)
    {
        Gate::authorize('update', $device);

        if ($endpoint->connection->device_id !== $device->device_id) {
            abort(404);
        }

        // FIX: Added logic to handle adding a new endpoint via the connection update method
        if ($request->input('action_type') === 'add_endpoint') {
            $connection = $endpoint->connection;
            return $this->storeEndpoint($request, $device, $connection);
        }

        // Standard endpoint update validation
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'path' => 'required|string|max:2048',
            'method' => 'required|in:GET,POST,PUT,DELETE',
            'metric_map_json' => 'required|json',
        ]);

        $endpoint->update([
            'name' => $validated['name'],
            'path' => $validated['path'],
            'method' => $validated['method'],
            'metric_map' => json_decode($validated['metric_map_json'], true),
        ]);

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Endpoint updated successfully.');
    }

    // NEW: Private helper method to store endpoints
    private function storeEndpoint(Request $request, Device $device, RestApiConnection $connection)
    {
        // Validation for new endpoint creation
        $validated = $request->validate([
            'endpoint_name' => 'required|string|max:255',
            'endpoint_path' => 'required|string|max:2048',
            'endpoint_method' => 'required|in:GET,POST,PUT,DELETE',
            'endpoint_metric_map_json' => 'required|json',
        ]);

        $connection->endpoints()->create([
            'name' => $validated['endpoint_name'],
            'path' => $validated['endpoint_path'],
            'method' => $validated['endpoint_method'],
            'metric_map' => json_decode($validated['endpoint_metric_map_json'], true),
        ]);

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'New endpoint added successfully.');
    }

    public function destroyEndpoint(Device $device, RestApiEndpoint $endpoint)
    {
        Gate::authorize('update', $device);

        if ($endpoint->connection->device_id !== $device->device_id) {
            abort(404);
        }

        $endpoint->delete();

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Endpoint deleted successfully.');
    }

    private function replacePlaceholdersInArray(array $data, Device $device): array
    {
        // ... (implementation remains the same)
        array_walk_recursive($data, function (&$value) use ($device) {
            if (is_string($value)) {
                $value = $this->replacePlaceholdersInString($value, $device);
            }
        });

        return $data;
    }

    private function replacePlaceholdersInString(string $string, Device $device): string
    {
        // ... (implementation remains the same)
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $matches[0][$index];
                $string = Str::replace($fullPlaceholder, $attribValue, $string);
            }
        }

        return $string;
    }
}