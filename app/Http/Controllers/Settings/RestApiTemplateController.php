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
            'resource_type' => 'nullable|string|max:50',
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
            'resource_type' => 'nullable|string|max:50',
            'template_data' => 'required',
            'description' => 'nullable|string',
        ]);

        if (is_string($validated['template_data'])) {
            $validated['template_data'] = json_decode($validated['template_data'], true);
        }

        $validated['template_data'] = $this->cleanTemplateMappings($validated['template_data']);

        $template->update($validated);

        return redirect()
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

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isJson($value)) {
                $result[$key] = json_decode($value, true);
            } elseif (is_array($value)) {
                $result[$key] = $this->cleanTemplateMappings($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
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
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->replacePlaceholdersInArray($value, $device);
            } elseif (is_string($value)) {
                $result[$key] = $this->replacePlaceholdersInString($value, $device);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    private function replacePlaceholdersInString(string $string, \App\Models\Device $device): string
    {
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);
        $string = Str::replace('{{ $device->sysName }}', $device->sysName, $string);
        $string = Str::replace('{device_hostname}', $device->hostname, $string);
        $string = Str::replace('{device_ip}', $device->ip, $string);
        $string = Str::replace('{device_sysname}', $device->sysName, $string);

        if (strpos($string, 'device_attrib') !== false) {
            $attribMatches = [];
            @preg_match_all('/\{device_attrib:([^}]+)\}/', $string, $attribMatches);
            
            if (!empty($attribMatches[1])) {
                foreach ($attribMatches[1] as $index => $attribName) {
                    if (isset($attribMatches[0][$index])) {
                        $attribValue = $device->getAttrib(trim($attribName));
                        $string = Str::replace($attribMatches[0][$index], $attribValue ?? '', $string);
                    }
                }
            }
        }

        return $string;
    }

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

        array_splice($templateData['connections'][$connIndex]['endpoints'], $epIndex, 1);

        $template->update(['template_data' => $templateData]);

        return response()->json([
            'success' => true,
            'message' => 'Endpoint deleted successfully'
        ]);
    }

    public function getTemplatePreview(Request $request)
    {
        try {
            $templateId = $request->input('template_id');
            $connIdx = $request->input('connection_index');
            $epIdx = $request->input('endpoint_index');
            $deviceId = $request->input('device_id');
            $credentialId = $request->input('credential_id');

            if (!$templateId || !is_numeric($connIdx) || !is_numeric($epIdx)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Missing required parameters'
                ], 400);
            }

            $template = RestApiTemplate::findOrFail($templateId);

            \Log::info('getTemplatePreview called with device_id=' . $deviceId . ', credential_id=' . $credentialId);

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

            if (empty($endpointData['path'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint path is required'
                ], 400);
            }

            if ($deviceId) {
                $device = \App\Models\Device::findOrFail($deviceId);
                $connData = $this->replacePlaceholdersInArray($connData, $device);
                $endpointData = $this->replacePlaceholdersInArray($endpointData, $device);
            }

            $apiResponse = $this->fetchTemplateApiResponse($connData, $endpointData, $credentialId);

            $vendorMapperFactory = new \App\RestApi\Vendors\VendorMapperFactory();
            $device = $deviceId ? \App\Models\Device::findOrFail($deviceId) : null;
            
            $recommendations = [];
            if ($device && $apiResponse) {
                $tempEndpoint = new \App\Models\RestApiEndpoint();
                $tempEndpoint->fill($endpointData);
                
                try {
                    $vendorMapper = $vendorMapperFactory->getMapper($device, $tempEndpoint);
                    $recommendations = $vendorMapper->getRecommendedMappings($apiResponse, $tempEndpoint);
                } catch (\Exception $e) {
                    \Log::warning('Failed to get vendor mapper: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'preview' => $apiResponse,
                'recommendations' => $recommendations,
            ]);

        } catch (\Throwable $e) {
            \Log::error('Template preview error: ' . $e->getMessage() . ' :: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

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

        $headers = $this->getTemplateAuthHeaders($connData, $client, $credentialId);
        $method = $endpointData['method'] ?? 'GET';
        $path = $endpointData['path'];

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

    private function getTemplateAuthHeaders(array $connData, $client, $credentialId = null): array
    {
        $credId = $credentialId ?? $connData['credential_id'] ?? null;
        
        if (!$credId) {
            \Log::info('No credential_id in connection data or provided');
            return [];
        }

        $credential = \App\Models\RestApiCredential::findOrFail($credId);
        $authType = Str::lower($credential->authenticationType->name);
        
        \Log::info("Using authentication type: {$authType}");

        if ($authType === 'session token') {
            \Log::info('Session token auth detected - performing login first');
            
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

        \Log::info("Using {$authType} authentication directly");
        return \App\RestApi\Credentials\CredentialHelper::getAuthHeaderFromModel($credential);
    }

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
            \Log::error('Failed to load devices: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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
                        \Log::warning('Error loading auth type for credential ' . $cred->id);
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
            \Log::error('Failed to load credentials: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
