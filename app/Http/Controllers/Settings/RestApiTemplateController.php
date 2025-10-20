<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RestApiTemplateController extends Controller
{
    public function index()
    {
        $templates = RestApiTemplate::all();
        return view('settings.rest-api.templates.index', compact('templates'));
    }

    public function create()
    {
        $template = new RestApiTemplate();
        return view('settings.rest-api.templates.create', compact('template'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:rest_api_templates,name|max:255',
            'vendor' => 'nullable|string|max:255',
            'resource_type' => 'nullable|string|max:50', // ADDED validation for template-level resource_type
            'template_data' => 'required|json',
            'description' => 'nullable|string',
        ]);

        $validated['template_data'] = json_decode($validated['template_data'], true);
        $validated['template_data'] = $this->cleanTemplateMappings($validated['template_data']);

        RestApiTemplate::create($validated);

        return redirect()->route('devices.rest-api.templates.index')->with('success', 'Template created successfully.');
    }

    public function edit(RestApiTemplate $template)
    {
        return view('settings.rest-api.templates.edit', compact('template'));
    }

    public function update(Request $request, RestApiTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:rest_api_templates,name,' . $template->id,
            'vendor' => 'nullable|string|max:255',
            'resource_type' => 'nullable|string|max:50', // ADDED validation for template-level resource_type
            'template_data' => 'required',
            'description' => 'nullable|string',
        ]);

        if (is_string($validated['template_data'])) {
            $validated['template_data'] = json_decode($validated['template_data'], true);
        }

        $validated['template_data'] = $this->cleanTemplateMappings($validated['template_data']);

        // Normalize booleans and ensure resource_type is saved from the endpoint form
        if (isset($validated['template_data']['connections'])) {
            foreach ($validated['template_data']['connections'] as &$connection) {
                // Normalize SSL verify flag
                if (isset($connection['disable_ssl_verify'])) {
                    $connection['disable_ssl_verify'] = filter_var(
                        $connection['disable_ssl_verify'],
                        FILTER_VALIDATE_BOOLEAN
                    );
                }

                if (isset($connection['endpoints'])) {
                    foreach ($connection['endpoints'] as &$endpoint) {
                        // Normalize enabled field
                        $endpoint['enabled'] = filter_var($endpoint['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

                        // Ensure endpoint resource_type is captured
                        $endpoint['resource_type'] = $endpoint['resource_type'] ?? 'unknown';

                        if (isset($endpoint['metric_map']) && is_string($endpoint['metric_map']) && $this->isJson($endpoint['metric_map'])) {
                            $endpoint['metric_map'] = json_decode($endpoint['metric_map'], true);
                        }

                        if (isset($endpoint['response_mapping']) && is_string($endpoint['response_mapping']) && $this->isJson($endpoint['response_mapping'])) {
                            $endpoint['response_mapping'] = json_decode($endpoint['response_mapping'], true);
                        }
                    }
                }
            }
        }

            $template->update($validated);

				    return redirect()
				        // FIX: Change 'devices.rest-api' to the correct 'settings.rest-api' prefix
				        ->route('settings.rest-api.templates.edit', $template->id)
				        ->with('success', 'Template updated successfully.');
    }

    public function destroy(RestApiTemplate $template)
    {
        $template->delete();
        return redirect()->route('devices.rest-api.templates.index')
                         ->with('success', 'Template deleted successfully.');
    }

    private function cleanTemplateMappings($data)
    {
        if (!is_array($data)) {
            $data = json_decode($data, true) ?? [];
        }

        foreach ($data as $key => &$value) {
            if (is_string($value) && $this->isJson($value)) {
                $value = json_decode($value, true);
            }

            if (is_array($value)) {
                foreach ($value as $subKey => &$subValue) {
                    if (is_string($subValue) && $this->isJson($subValue)) {
                        $subValue = json_decode($subValue, true);
                    }

                    if (is_array($subValue)) {
                        foreach ($subValue as $innerKey => &$innerValue) {
                            if (is_string($innerValue) && $this->isJson($innerValue)) {
                                $innerValue = json_decode($innerValue, true);
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    private function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function replacePlaceholdersInArray(array $data, \App\Models\Device $device): array
    {
        // Recursively process array to replace placeholders
        $processArray = function(&$item) use ($device, &$processArray) {
            if (is_array($item)) {
                foreach ($item as &$value) {
                    $processArray($value);
                }
            } elseif (is_string($item)) {
                $item = $this->replacePlaceholdersInString($item, $device);
            }
        };
        
        $processArray($data);
        return $data;
    }

    private function replacePlaceholdersInString(string $string, \App\Models\Device $device): string
    {
        // Support Laravel Blade-style placeholders: {{ $device->hostname }}
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);
        $string = Str::replace('{{ $device->sysName }}', $device->sysName, $string);

        // Support simple placeholder format: {device_hostname}
        $string = Str::replace('{device_hostname}', $device->hostname, $string);
        $string = Str::replace('{device_ip}', $device->ip, $string);
        $string = Str::replace('{device_sysname}', $device->sysName, $string);

        // Support getAttrib for custom attributes
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

    /**
     * Add a new endpoint to the template's JSON data
     */
    public function addEndpoint(Request $request, RestApiTemplate $template)
    {
        $validated = $request->validate([
            'connection_index' => 'required|integer',
            'endpoint_data' => 'required|array',
            'endpoint_data.name' => 'required|string|max:255',
            'endpoint_data.path' => 'required|string|max:2048',
            'endpoint_data.method' => 'required|in:GET,POST,PUT,DELETE',
            'endpoint_data.resource_type' => 'nullable|string|max:50',
            'endpoint_data.metric_map' => 'nullable|array',
        ]);

        $templateData = is_array($template->template_data)
            ? $template->template_data
            : json_decode($template->template_data, true);

        $connIndex = $validated['connection_index'];

        if (!isset($templateData['connections'][$connIndex])) {
            return response()->json([
                'success' => false,
                'message' => 'Connection not found in template'
            ], 404);
        }

        // Add the new endpoint
        if (!isset($templateData['connections'][$connIndex]['endpoints'])) {
            $templateData['connections'][$connIndex]['endpoints'] = [];
        }

        $templateData['connections'][$connIndex]['endpoints'][] = $validated['endpoint_data'];
        $newEndpointIndex = count($templateData['connections'][$connIndex]['endpoints']) - 1;

        $template->update(['template_data' => $templateData]);

        return response()->json([
            'success' => true,
            'message' => 'Endpoint added successfully',
            'endpoint' => array_merge(
                $validated['endpoint_data'],
                [
                    '_connection_index' => $connIndex,
                    '_endpoint_index' => $newEndpointIndex,
                    '_is_template' => true
                ]
            )
        ]);
    }

    /**
     * Update an endpoint in the template's JSON data
     */
    public function updateEndpoint(Request $request, RestApiTemplate $template)
    {
        $validated = $request->validate([
            'connection_index' => 'required|integer',
            'endpoint_index' => 'required|integer',
            'endpoint_data' => 'required|array',
            'endpoint_data.name' => 'required|string|max:255',
            'endpoint_data.path' => 'required|string|max:2048',
            'endpoint_data.method' => 'required|in:GET,POST,PUT,DELETE',
            'endpoint_data.resource_type' => 'nullable|string|max:50',
            'endpoint_data.metric_map' => 'nullable|array',
        ]);

        $templateData = is_array($template->template_data)
            ? $template->template_data
            : json_decode($template->template_data, true);

        $connIndex = $validated['connection_index'];
        $epIndex = $validated['endpoint_index'];

        if (!isset($templateData['connections'][$connIndex]['endpoints'][$epIndex])) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint not found in template'
            ], 404);
        }

        // Update the endpoint
        $templateData['connections'][$connIndex]['endpoints'][$epIndex] = array_merge(
            $templateData['connections'][$connIndex]['endpoints'][$epIndex],
            $validated['endpoint_data']
        );

        $template->update(['template_data' => $templateData]);

        return response()->json([
            'success' => true,
            'message' => 'Endpoint updated successfully',
            'endpoint' => array_merge(
                $validated['endpoint_data'],
                [
                    '_connection_index' => $connIndex,
                    '_endpoint_index' => $epIndex,
                    '_is_template' => true
                ]
            )
        ]);
    }

    /**
     * Delete an endpoint from the template's JSON data
     */
    public function deleteEndpoint(Request $request, RestApiTemplate $template)
    {
        $validated = $request->validate([
            'connection_index' => 'required|integer',
            'endpoint_index' => 'required|integer',
        ]);

        $templateData = is_array($template->template_data)
            ? $template->template_data
            : json_decode($template->template_data, true);

        $connIndex = $validated['connection_index'];
        $epIndex = $validated['endpoint_index'];

        if (!isset($templateData['connections'][$connIndex]['endpoints'][$epIndex])) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint not found in template'
            ], 404);
        }

        // Remove the endpoint
        array_splice($templateData['connections'][$connIndex]['endpoints'], $epIndex, 1);

        $template->update(['template_data' => $templateData]);

        return response()->json([
            'success' => true,
            'message' => 'Endpoint deleted successfully'
        ]);
    }

    /**
     * API endpoint: Get API preview for template endpoint configuration
     * Called from endpoint-form.blade.php when user clicks "Fetch API Preview"
     * 
     * Handles authentication using the same logic as polling:
     * - API Key: Direct header
     * - Session Token: Login first to get x-auth-token
     * - Bearer Token: Direct header
     * - Basic Auth: Direct header
     * 
     * POST /api/rest-api/template-preview
     */
    public function getTemplatePreview(Request $request)
    {
        try {
            $validated = $request->validate([
                'template_id' => 'required|exists:rest_api_templates,id',
                'connection_index' => 'required|integer|min:0',
                'endpoint_index' => 'required|integer|min:0',
                'device_id' => 'nullable|exists:devices,device_id',
                'credential_id' => 'nullable|exists:rest_api_credentials,id',
            ]);

            $template = RestApiTemplate::findOrFail($validated['template_id']);
            $connIdx = $validated['connection_index'];
            $epIdx = $validated['endpoint_index'];
            $deviceId = $validated['device_id'] ?? null;
            $credentialId = $validated['credential_id'] ?? null;

            \Log::info('getTemplatePreview called with device_id=' . $deviceId . ', credential_id=' . $credentialId);

            try {
            // Get template data
            $templateData = is_array($template->template_data) 
                ? $template->template_data 
                : json_decode($template->template_data, true);

            if (!isset($templateData['connections'][$connIdx])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Connection not found in template'
                ], 404);
            }

            if (!isset($templateData['connections'][$connIdx]['endpoints'][$epIdx])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint not found in template'
                ], 404);
            }

            $connData = $templateData['connections'][$connIdx];
            $endpointData = $connData['endpoints'][$epIdx];

            // Validate required endpoint fields
            if (empty($endpointData['path'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint path is required'
                ], 400);
            }

            // If device_id is provided, use it to replace placeholders
            if ($deviceId) {
                $device = \App\Models\Device::findOrFail($deviceId);
                $connData = $this->replacePlaceholdersInArray($connData, $device);
                $endpointData = $this->replacePlaceholdersInArray($endpointData, $device);
            }

            // Fetch API response
            $apiResponse = $this->fetchTemplateApiResponse($connData, $endpointData, $credentialId);

            // Get vendor mapper for recommendations
            $vendorMapperFactory = new \App\RestApi\Vendors\VendorMapperFactory();
            $device = $deviceId ? \App\Models\Device::findOrFail($deviceId) : null;
            
            $recommendations = [];
            if ($device && $apiResponse) {
                // Create a temporary endpoint object for mapper
                $tempEndpoint = new \App\Models\RestApiEndpoint();
                $tempEndpoint->fill($endpointData);
                
                try {
                    $vendorMapper = $vendorMapperFactory->getMapper($device, $tempEndpoint);
                    $recommendations = $vendorMapper->getRecommendedMappings($apiResponse, $tempEndpoint);
                } catch (\Exception $e) {
                    \Log::warning('Failed to get vendor mapper: ' . $e->getMessage());
                    // Continue without recommendations if mapper fails
                }
            }

            return response()->json([
                'success' => true,
                'preview' => $apiResponse,
                'recommendations' => $recommendations,
            ]);

            } catch (\Exception $e) {
                \Log::error("Template preview inner error: " . $e->getMessage());
                throw $e;
            }
        } catch (\Throwable $e) {
            \Log::error('Template preview error: ' . $e->getMessage() . ' :: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Fetch API response for template endpoint
     * Handles authentication the same way as actual polling
     * 
     * @param array $connData Connection configuration from template
     * @param array $endpointData Endpoint configuration from template
     * @return array API response
     * @throws \Exception
     */
    private function fetchTemplateApiResponse(array $connData, array $endpointData, $credentialId = null): array
    {
        if (empty($connData['base_url'])) {
            throw new \Exception('Base URL not configured');
        }

        $client = new \GuzzleHttp\Client([
            'base_uri' => $connData['base_url'],
            'timeout' => 15,
            'verify' => !($connData['disable_ssl_verify'] ?? false),
        ]);

        // Get authentication headers
        $headers = $this->getTemplateAuthHeaders($connData, $client, $credentialId);

        // Build the request
        $method = $endpointData['method'] ?? 'GET';
        $path = $endpointData['path'];

        // Make the request
        $response = $client->request($method, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \Exception("HTTP {$response->getStatusCode()}");
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);

        if ($decoded === null) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Get authentication headers for template endpoint
     * Uses the same authentication logic as actual polling
     * Supports: API Key, Session Token (with login), Bearer Token, Basic Auth
     * 
     * For Session Token: First performs a POST to the login endpoint to obtain the token
     * For other types: Uses direct authentication headers
     * 
     * @param array $connData Connection configuration
     * @param $client Guzzle HTTP Client for session token login
     * @return array Headers array
     * @throws \Exception if authentication fails
     */
    private function getTemplateAuthHeaders(array $connData, $client, $credentialId = null): array
    {
        // Use override credential if provided, otherwise use connection's credential
        $credId = $credentialId ?? $connData['credential_id'] ?? null;
        
        if (!$credId) {
            \Log::info('No credential_id in connection data or provided');
            return [];
        }

        $credential = \App\Models\RestApiCredential::findOrFail($credId);
        $authType = Str::lower($credential->authenticationType->name);
        
        \Log::info("Using authentication type: {$authType}");

        // For session token, we need to login first to get the token
        if ($authType === 'session token') {
            \Log::info('Session token auth detected - performing login first');
            
            // Use the shared CredentialHelper to obtain session token
            $sessionToken = \App\RestApi\Credentials\CredentialHelper::obtainSessionToken(
                $credential,
                $connData['base_url'],
                [
                    'login_method' => $connData['login_method'] ?? 'POST',
                    'login_path' => $connData['login_path'] ?? '/api/login',
                    'api_token_header' => $connData['api_token_header'] ?? 'api-token',
                    'session_token_header' => $connData['token_header'] ?? 'x-auth-token',
                ],
                !($connData['disable_ssl_verify'] ?? false)
            );

            if ($sessionToken) {
                $params = $credential->params->pluck('value', 'key')->toArray();
                $tokenHeader = $params['token_header'] ?? 'x-auth-token';
                \Log::info("✓ Session token obtained, using header: {$tokenHeader}");
                return [
                    $tokenHeader => $sessionToken,
                ];
            } else {
                \Log::warning('Failed to obtain session token');
                throw new \Exception('Failed to obtain session token during preview');
            }
        }

        // For other auth types, use CredentialHelper
        \Log::info("Using {$authType} authentication directly");
        return \App\RestApi\Credentials\CredentialHelper::getAuthHeaderFromModel($credential);
    }

    /**
     * Get list of devices for selector dropdown
     * GET /api/rest-api/devices
     */
    public function getDevicesList(Request $request)
    {
        try {
            $devices = \App\Models\Device::query()
                ->select('device_id', 'hostname', 'ip')
                ->orderBy('hostname')
                ->limit(1000)
                ->get()
                ->toArray();

            return response()->json([
                'success' => true,
                'devices' => $devices,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to load devices: ' . $e->getMessage() . ' :: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of REST API credentials for selector dropdown
     * GET /api/rest-api/credentials
     */
    public function getCredentialsList(Request $request)
    {
        try {
            $credentials = \App\Models\RestApiCredential::query()
                ->with('authenticationType')
                ->orderBy('name')
                ->limit(1000)
                ->get()
                ->map(function ($cred) {
                    $authTypeName = 'Unknown';
                    try {
                        if ($cred->authenticationType) {
                            $authTypeName = $cred->authenticationType->name;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Error loading auth type for credential ' . $cred->id . ': ' . $e->getMessage());
                    }
                    return [
                        'id' => $cred->id,
                        'name' => $cred->name,
                        'auth_type' => $authTypeName,
                        'description' => null,
                    ];
                })
                ->toArray();

            return response()->json([
                'success' => true,
                'credentials' => $credentials,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to load credentials: ' . $e->getMessage() . ' :: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}