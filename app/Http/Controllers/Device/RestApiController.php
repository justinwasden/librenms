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
use Illuminate\Validation\Validator;

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
                'enabled' => $connData['enabled'] ?? true,
                'disable_ssl_verify' => $connData['disable_ssl_verify'] ?? false,
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

        // Validate with flexible URL format
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => ['required', 'string', 'max:2048', function($attribute, $value, $fail) {
                // Allow any string that starts with http:// or https://
                if (!preg_match('/^https?:\/\/.+/', $value)) {
                    $fail('The base url must start with http:// or https://');
                }
            }],
            'rate_limit' => 'nullable|integer|min:1',
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

        if ($connection->device_id !== $device->device_id) {
            abort(404);
        }

        // Flexible validation for base_url
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => ['required', 'string', 'max:2048', function($attribute, $value, $fail) {
                // Allow any string that starts with http:// or https://
                // This is less strict than Laravel's 'url' rule which requires DNS-resolvable hostnames
                if (!preg_match('/^https?:\/\/.+/', $value)) {
                    $fail('The base url must start with http:// or https://');
                }
            }],
            'rate_limit' => 'nullable|integer|min:1',
        ]);

        // Handle checkboxes
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

        $connection->update($validated);

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Credentials applied successfully.');
    }

    public function destroyConnection(Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

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

        // Handle adding a new endpoint via the connection update method
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
        array_walk_recursive($data, function (&$value) use ($device) {
            if (is_string($value)) {
                $value = $this->replacePlaceholdersInString($value, $device);
            }
        });

        return $data;
    }

    private function replacePlaceholdersInString(string $string, Device $device): string
    {
        // Support Laravel Blade-style placeholders: {{ $device->hostname }}
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);
        $string = Str::replace('{{ $device->sysName }}', $device->sysName, $string);
        $string = Str::replace('{{ $device->display }}', $device->display ?? $device->hostname, $string);
        
        // Support simple placeholder format: {device_hostname}
        $string = Str::replace('{device_hostname}', $device->hostname, $string);
        $string = Str::replace('{device_ip}', $device->ip, $string);
        $string = Str::replace('{device_sysname}', $device->sysName, $string);
        $string = Str::replace('{device_display}', $device->display ?? $device->hostname, $string);
        
        // Support getAttrib for custom attributes: {{ $device->getAttrib('name') }}
        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $matches[0][$index];
                $string = Str::replace($fullPlaceholder, $attribValue ?? '', $string);
            }
        }
        
        // Support simple attrib format: {device_attrib:name}
        preg_match_all('/\{device_attrib:([^}]+)\}/', $string, $attribMatches);
        
        if (!empty($attribMatches[1])) {
            foreach ($attribMatches[1] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $attribMatches[0][$index];
                $string = Str::replace($fullPlaceholder, $attribValue ?? '', $string);
            }
        }

        return $string;
    }
}
