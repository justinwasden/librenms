<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;

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
		        'template_data' => 'required|json',
		        'description' => 'nullable|string',
		    ]);

		    $validated['template_data'] = json_decode($validated['template_data'], true);

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
            'template_data' => 'required|json',
            'description' => 'nullable|string',
        ]);

        $validated['template_data'] = json_decode($validated['template_data'], true);

        $template->update($validated);

        return redirect()->route('devices.rest-api.templates.index')->with('success', 'Template updated successfully.');
    }

    public function destroy(RestApiTemplate $template)
    {
        $template->delete();
        return redirect()->route('devices.rest-api.templates.index')->with('success', 'Template deleted successfully.');
    }

    /**
     * Test a template against a device
     */
    public function test(Request $request, RestApiTemplate $template)
    {
        $request->validate([
            'device_id' => 'required|exists:devices,device_id',
            'test_all_endpoints' => 'boolean',
            'specific_endpoint' => 'nullable|string',
            'verify_ssl' => 'boolean',
            'show_headers' => 'boolean',
            'verbose' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:300',
        ]);

        $device = \App\Models\Device::find($request->device_id);
        $testAllEndpoints = $request->get('test_all_endpoints', false);
        $specificEndpoint = $request->get('specific_endpoint');
        $verifySsl = $request->get('verify_ssl', false);
        $showHeaders = $request->get('show_headers', false);
        $verbose = $request->get('verbose', false);
        $timeout = $request->get('timeout', 30);

        // Replace placeholders in template
        $templateData = $this->replacePlaceholdersInArray($template->template_data, $device);

        $results = [
            'success' => true,
            'summary' => [
                'device' => $device->hostname,
                'connection' => $templateData['connections'][0]['name'] ?? 'Unknown',
                'base_url' => $templateData['connections'][0]['base_url'] ?? 'Unknown',
                'endpoints_tested' => 0,
                'success_rate' => 0,
                'total_time' => 0,
            ],
            'endpoint_results' => [],
        ];

        $client = new \GuzzleHttp\Client([
            'timeout' => $timeout,
            'connect_timeout' => 10,
            'verify' => $verifySsl,
        ]);

        $successCount = 0;
        $totalCount = 0;
        $totalTime = 0;

        foreach ($templateData['connections'] as $connIndex => $connData) {
            $baseUrl = $connData['base_url'];
            $endpoints = $connData['endpoints'] ?? [];

            // Handle specific endpoint selection
            if ($specificEndpoint) {
                $parts = explode('-', $specificEndpoint);
                if (count($parts) === 2 && $connIndex == $parts[0]) {
                    $endpoints = isset($endpoints[$parts[1]]) ? [$endpoints[$parts[1]]] : [];
                } else {
                    $endpoints = [];
                }
            } elseif (!$testAllEndpoints && count($endpoints) > 0) {
                // If not testing all and no specific endpoint, only test first
                $endpoints = [$endpoints[0]];
            }

            foreach ($endpoints as $endpointData) {
                $totalCount++;
                $startTime = microtime(true);

                try {
                    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpointData['path'] ?? '', '/');
                    $method = $endpointData['method'] ?? 'GET';

                    $options = [];

                    // Add authentication if credential_id is set
                    if (isset($connData['credential_id']) && $connData['credential_id']) {
                        $credential = \App\Models\RestApiCredential::find($connData['credential_id']);
                        if ($credential) {
                            $authType = strtolower($credential->authenticationType->name ?? '');
                            $params = $credential->params->pluck('value', 'key');

                            if ($authType === 'basic auth' && isset($params['username'], $params['password'])) {
                                $options['auth'] = [$params['username'], $params['password']];
                            } elseif ($authType === 'token' && isset($params['token'], $params['header'])) {
                                $scheme = !empty($params['scheme']) ? $params['scheme'] . ' ' : '';
                                $options['headers'][$params['header']] = $scheme . $params['token'];
                            }
                        }
                    }

                    $response = $client->request($method, $url, $options);
                    $statusCode = $response->getStatusCode();
                    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                    $totalTime += $responseTime;
                    $body = $response->getBody()->getContents();

                    // Try to decode as JSON for pretty preview
                    $jsonBody = json_decode($body, true);
                    $responsePreview = $jsonBody 
                        ? json_encode($jsonBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $body;

                    // Limit preview size
                    if (strlen($responsePreview) > 5000) {
                        $responsePreview = substr($responsePreview, 0, 5000) . "\n\n... (truncated)";
                    }

                    $success = $statusCode >= 200 && $statusCode < 300;
                    if ($success) {
                        $successCount++;
                    }

                    $result = [
                        'name' => $endpointData['name'] ?? 'Unnamed Endpoint',
                        'url' => $url,
                        'method' => $method,
                        'status_code' => $statusCode,
                        'response_time' => $responseTime,
                        'success' => $success,
                        'response_preview' => $responsePreview,
                        'error' => null,
                    ];

                    // Add headers if requested
                    if ($showHeaders) {
                        $headers = [];
                        foreach ($response->getHeaders() as $name => $values) {
                            $headers[$name] = implode(', ', $values);
                        }
                        $result['headers'] = $headers;
                    }

                    // Add verbose info if requested
                    if ($verbose) {
                        $result['verbose'] = [
                            'request_url' => $url,
                            'request_method' => $method,
                            'response_size' => strlen($body),
                            'content_type' => $response->getHeaderLine('Content-Type'),
                        ];
                    }

                    $results['endpoint_results'][] = $result;

                } catch (\GuzzleHttp\Exception\RequestException $e) {
                    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                    $totalTime += $responseTime;
                    $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
                    $errorMessage = $e->getMessage();

                    if ($e->hasResponse()) {
                        $errorBody = $e->getResponse()->getBody()->getContents();
                        if (strlen($errorBody) < 500) {
                            $errorMessage .= ': ' . $errorBody;
                        }
                    }

                    $results['endpoint_results'][] = [
                        'name' => $endpointData['name'] ?? 'Unnamed Endpoint',
                        'url' => rtrim($baseUrl, '/') . '/' . ltrim($endpointData['path'] ?? '', '/'),
                        'method' => $endpointData['method'] ?? 'GET',
                        'status_code' => $statusCode,
                        'response_time' => $responseTime,
                        'success' => false,
                        'response_preview' => null,
                        'error' => $errorMessage,
                    ];

                } catch (\Exception $e) {
                    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                    $totalTime += $responseTime;

                    $results['endpoint_results'][] = [
                        'name' => $endpointData['name'] ?? 'Unnamed Endpoint',
                        'url' => rtrim($baseUrl, '/') . '/' . ltrim($endpointData['path'] ?? '', '/'),
                        'method' => $endpointData['method'] ?? 'GET',
                        'status_code' => null,
                        'response_time' => $responseTime,
                        'success' => false,
                        'response_preview' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $results['summary']['endpoints_tested'] = $totalCount;
        $results['summary']['total_time'] = round($totalTime, 2);
        $results['summary']['success_rate'] = $totalCount > 0 ? round(($successCount / $totalCount) * 100) : 0;
        $results['success'] = $successCount === $totalCount && $totalCount > 0;

        return response()->json($results);
    }

    /**
     * Replace placeholders in array recursively
     */
    private function replacePlaceholdersInArray(array $data, \App\Models\Device $device): array
    {
        array_walk_recursive($data, function (&$value) use ($device) {
            if (is_string($value)) {
                $value = $this->replacePlaceholdersInString($value, $device);
            }
        });

        return $data;
    }

    /**
     * Replace placeholders in string
     */
    private function replacePlaceholdersInString(string $string, \App\Models\Device $device): string
    {
        // Support Laravel Blade-style placeholders: {{ $device->hostname }}
        $string = \Illuminate\Support\Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = \Illuminate\Support\Str::replace('{{ $device->ip }}', $device->ip, $string);
        $string = \Illuminate\Support\Str::replace('{{ $device->sysName }}', $device->sysName, $string);
        
        // Support simple placeholder format: {device_hostname}
        $string = \Illuminate\Support\Str::replace('{device_hostname}', $device->hostname, $string);
        $string = \Illuminate\Support\Str::replace('{device_ip}', $device->ip, $string);
        $string = \Illuminate\Support\Str::replace('{device_sysname}', $device->sysName, $string);
        
        // Support getAttrib for custom attributes
        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $matches[0][$index];
                $string = \Illuminate\Support\Str::replace($fullPlaceholder, $attribValue ?? '', $string);
            }
        }
        
        // Support simple attrib format: {device_attrib:name}
        preg_match_all('/\{device_attrib:([^}]+)\}/', $string, $attribMatches);
        
        if (!empty($attribMatches[1])) {
            foreach ($attribMatches[1] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $attribMatches[0][$index];
                $string = \Illuminate\Support\Str::replace($fullPlaceholder, $attribValue ?? '', $string);
            }
        }

        return $string;
    }

    /**
     * Get session token for session-based authentication
     */
    private function getSessionToken(array $connData, \App\Models\Device $device, $client, bool $verifySsl): ?string
    {
        if (!isset($connData['credential_id'])) {
            return null;
        }

        $credential = \App\Models\RestApiCredential::find($connData['credential_id']);
        if (!$credential || strtolower($credential->authenticationType->name) !== 'session token') {
            return null;
        }

        try {
            $params = $credential->params->pluck('value', 'key');

            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                return null;
            }

            $loginUrl = rtrim($connData['base_url'], '/') . '/' . ltrim($loginPath, '/');

            $loginOptions = [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
                'verify' => $verifySsl,
            ];

            $loginMethod = strtoupper($params['login_method'] ?? 'POST');
            $response = $client->request($loginMethod, $loginUrl, $loginOptions);

            $sessionToken = null;
            if ($response->hasHeader($tokenHeader)) {
                $sessionToken = $response->getHeader($tokenHeader)[0] ?? null;
            }

            return $sessionToken;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to obtain session token for test: " . $e->getMessage());
            return null;
        }
    }