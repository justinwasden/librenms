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
        // Filter templates by vendor/OS (as per RestApiTemplate scope)
        $templates = \App\Models\RestApiTemplate::all()
   				 ->sortByDesc(function ($template) use ($device) {
		        // Simple prioritization logic: 2 if OS matches, 1 if Vendor matches, 0 otherwise.
		        $score = 0;
		        if ($template->os && str_contains($device->os, $template->os)) {
		            $score += 2;
		        }
		        if ($template->vendor && str_contains($device->vendor, $template->vendor)) {
		            $score += 1;
		        }
        return $score;
    });

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
				        'port' => $connData['port'] ?? null, // ADDED PORT
				        'credential_id' => $connData['credential_id'] ?? null,
				        'rate_limit' => $connData['rate_limit'] ?? 60,
				        'enabled' => $connData['enabled'] ?? true,
				        'disable_ssl_verify' => $connData['disable_ssl_verify'] ?? false,
				    ]);

				    if (isset($connData['endpoints']) && is_array($connData['endpoints'])) {
				        foreach ($connData['endpoints'] as $endpointData) {
								    // Handle template_response_mapping (the actual field name in templates)
								    if (isset($endpointData['template_response_mapping'])) {
								        // Store directly as template_response_mapping (JSONColumn handles serialization)
								        $map = $endpointData['template_response_mapping'];
								        if (is_string($map)) {
								            $map = json_decode($map, true);
								        }
								    } else {
								        // Fallback for legacy field names
								        $map = null;
								        if (isset($endpointData['response_mapping'])) {
								            $map = $endpointData['response_mapping'];
								            if (is_string($map)) {
								                $map = json_decode($map, true);
								            }
								        } elseif (isset($endpointData['metric_map'])) {
								            $map = $endpointData['metric_map'];
								            if (is_string($map)) {
								                $map = json_decode($map, true);
								            }
								        }
								    }

                    // Ensure resource_type is set for the new endpoint column
                    $endpointData['resource_type'] = $endpointData['resource_type'] ?? $template->resource_type ?? 'unknown';

                    // Create endpoint with correct mapping field
                    $createData = [
                        'name' => $endpointData['name'] ?? 'Unnamed',
                        'path' => $endpointData['path'] ?? '/',
                        'http_method' => $endpointData['http_method'] ?? $endpointData['method'] ?? 'GET',
                        'resource_type' => $endpointData['resource_type'],
                        'template_response_mapping' => $map,
                        'enabled' => $endpointData['enabled'] ?? true,
                    ];

								    $connection->endpoints()->create($createData);
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
            'port' => 'nullable|integer|min:1|max:65535', // ADDED PORT VALIDATION
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

		    // --- FIX START: PIVOT TO STORE ENDPOINT ---
		    if ($request->input('action_type') === 'add_endpoint') {
		        // This is the correct entry point when submitting the 'Add Endpoint' modal.
		        // It immediately skips connection validation and proceeds to store the new endpoint.
		        return $this->storeEndpoint($request, $device, $connection);
		    }
		    // --- FIX END ---

		    // Original Validation for CONNECTION UPDATE follows:
		    $validated = $request->validate([
		        'name' => 'required|string|max:255',
		        'base_url' => ['required', 'string', 'max:2048', function($attribute, $value, $fail) {
		            // Allow any string that starts with http:// or https://
		            if (!preg_match('/^https?:\/\/.+/', $value)) {
		                $fail('The base url must start with http:// or https://');
		            }
		        }],
		        'port' => 'nullable|integer|min:1|max:65535', // ADDED PORT VALIDATION
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

    public function syncFromTemplate(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $request->validate(['template_id' => 'required|exists:rest_api_templates,id']);

        $template = RestApiTemplate::find($request->template_id);
        $templateData = $this->replacePlaceholdersInArray($template->template_data, $device);

        // Get existing connections for this device
        $existingConnections = $device->restApiConnections()->with('endpoints')->get();

        $syncedCount = 0;
        $addedCount = 0;
        $unchangedCount = 0;

        foreach ($templateData['connections'] as $connData) {
            // Find matching connection by name
            $connection = $existingConnections->firstWhere('name', $connData['name']);

            if (!$connection) {
                // Connection doesn't exist, skip (user should apply template first)
                continue;
            }

            // Get template endpoints for this connection
            $templateEndpoints = $connData['endpoints'] ?? [];

            foreach ($templateEndpoints as $templateEp) {
                // Handle template_response_mapping (preferred) or legacy field names
                if (isset($templateEp['template_response_mapping'])) {
                    $map = $templateEp['template_response_mapping'];
                    if (is_string($map)) {
                        $map = json_decode($map, true);
                    }
                } elseif (isset($templateEp['response_mapping'])) {
                    $map = $templateEp['response_mapping'];
                    if (is_string($map)) {
                        $map = json_decode($map, true);
                    }
                } elseif (isset($templateEp['metric_map'])) {
                    $map = $templateEp['metric_map'];
                    if (is_string($map)) {
                        $map = json_decode($map, true);
                    }
                } else {
                    $map = null;
                }

                // Ensure resource_type is set
                $templateEp['resource_type'] = $templateEp['resource_type'] ?? $template->resource_type ?? 'unknown';

                // Find matching endpoint by path
                $existingEndpoint = $connection->endpoints->firstWhere('path', $templateEp['path']);

                if ($existingEndpoint) {
                    // Update existing endpoint (but preserve device-specific base_url in connection)
                    $updated = $existingEndpoint->update([
                        'name' => $templateEp['name'],
                        'http_method' => $templateEp['http_method'] ?? $templateEp['method'] ?? 'GET',
                        'resource_type' => $templateEp['resource_type'],
                        'template_response_mapping' => $map,
                    ]);

                    if ($updated) {
                        $syncedCount++;
                    } else {
                        $unchangedCount++;
                    }
                } else {
                    // Add new endpoint from template
                    $connection->endpoints()->create([
                        'name' => $templateEp['name'],
                        'path' => $templateEp['path'],
                        'http_method' => $templateEp['http_method'] ?? $templateEp['method'] ?? 'GET',
                        'resource_type' => $templateEp['resource_type'],
                        'template_response_mapping' => $map,
                    ]);
                    $addedCount++;
                }
            }
        }

        $message = "Template sync complete: {$syncedCount} updated, {$addedCount} added, {$unchangedCount} unchanged.";
        return redirect()->route('device.edit.rest-api', $device)->with('success', $message);
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
		        'resource_type' => 'nullable|string|max:50',
		        'metric_map_json' => 'required|string',
		    ]);

		    // Decode the JSON safely (handles escaped or stringified JSON)
		    $decodedMap = json_decode(stripslashes($validated['metric_map_json']), true);

		    if (json_last_error() !== JSON_ERROR_NONE) {
		        return back()->withErrors(['metric_map_json' => 'Invalid JSON format.']);
		    }

		    $endpoint->update([
		        'name' => $validated['name'],
		        'path' => $validated['path'],
		        'http_method' => $validated['method'],
		        'resource_type' => $validated['resource_type'] ?? 'unknown',
		        'template_response_mapping' => $decodedMap,
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
		        'endpoint_resource_type' => 'nullable|string|max:50',
		        'endpoint_metric_map_json' => 'required|string',
		    ]);

		    $decodedMap = json_decode(stripslashes($validated['endpoint_metric_map_json']), true);

		    if (json_last_error() !== JSON_ERROR_NONE) {
		        return back()->withErrors(['endpoint_metric_map_json' => 'Invalid JSON format.']);
		    }

		    $connection->endpoints()->create([
		        'name' => $validated['endpoint_name'],
		        'path' => $validated['endpoint_path'],
		        'http_method' => $validated['endpoint_method'],
		        'resource_type' => $validated['endpoint_resource_type'] ?? 'unknown',
		        'template_response_mapping' => $decodedMap,
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

    protected function getSessionToken($connection): ?string
    {
        if (!$connection->credential || strtolower($connection->credential->authenticationType->name) !== 'session token') {
            return null;
        }

        $cacheKey = "rest_api_session_token:{$connection->id}";
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            return $cachedToken;
        }

        try {
            $params = $connection->credential->params->pluck('value', 'key');

            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                return null;
            }

            // START: Logic to construct full base URL with port
            $baseUrl = rtrim($connection->base_url, '/');
            $port = $connection->port;

            if ($port && !preg_match('/:\d+/', $baseUrl)) {
                 $isHttps = str_starts_with(strtolower($baseUrl), 'https');
                 $isHttp = str_starts_with(strtolower($baseUrl), 'http');

                 if (($isHttps && $port !== 443) || ($isHttp && $port !== 80)) {
                     // Append port if not explicitly set in base_url and it's not the default for the scheme
                     $baseUrl = $baseUrl . ":{$port}";
                 }
            }
            // END: Logic to construct full base URL with port

            $loginUrl = rtrim($baseUrl, '/') . '/' . ltrim($loginPath, '/');
            $loginUrl = $this->replacePlaceholdersInString($loginUrl, $this->device); // Fixed to use local helper

            $loginOptions = [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($connection->disable_ssl_verify) {
                $loginOptions['verify'] = false;
            }

            $loginMethod = strtoupper($params['login_method'] ?? 'POST');
            $response = $this->client->request($loginMethod, $loginUrl, $loginOptions);

            $sessionToken = null;
            if ($response->hasHeader($tokenHeader)) {
                $sessionToken = $response->getHeader($tokenHeader)[0] ?? null;
            }

            if (!$sessionToken) {
                return null;
            }

            $ttl = (int)($params['session_ttl'] ?? 3600);
            Cache::put($cacheKey, $sessionToken, $ttl);

            return $sessionToken;

        } catch (\Exception $e) {
            Log::error("Failed to obtain session token: " . $e->getMessage());
            return null;
        }
    }
}