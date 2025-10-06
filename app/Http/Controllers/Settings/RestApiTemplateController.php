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
            'template_data' => 'required|json',
            'description' => 'nullable|string',
        ]);

        $validated['template_data'] = json_decode($validated['template_data'], true);

        // FIX: Clean up potentially multi-line JSON strings (metric_map/response_mapping)
        // Ensure metric_map/response_mapping content is cleaned and saved as an array within template_data
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
            'template_data' => 'required',
            'description' => 'nullable|string',
        ]);

        // Handle template_data - it might come as JSON string or array
        if (is_string($validated['template_data'])) {
            $validated['template_data'] = json_decode($validated['template_data'], true);
        }

        // FIX: Clean up potentially multi-line JSON strings (metric_map/response_mapping)
        // Ensure metric_map/response_mapping content is cleaned and saved as an array within template_data
        $validated['template_data'] = $this->cleanTemplateMappings($validated['template_data']);


        // Ensure boolean values are properly converted for checkbox fields
        if (isset($validated['template_data']['connections'])) {
            foreach ($validated['template_data']['connections'] as $cIndex => &$connection) {
                // Convert disable_ssl_verify to boolean
                if (isset($connection['disable_ssl_verify'])) {
                    $connection['disable_ssl_verify'] = filter_var($connection['disable_ssl_verify'], FILTER_VALIDATE_BOOLEAN);
                }

                if (isset($connection['endpoints'])) {
                    foreach ($connection['endpoints'] as $eIndex => &$endpoint) {
                        // Convert enabled to boolean
                        $endpoint['enabled'] = filter_var($endpoint['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        }

        $template->update($validated);

        return redirect()->route('devices.rest-api.templates.edit', $template->id)
                         ->with('success', 'Template updated successfully.');
    }

    public function destroy(RestApiTemplate $template)
    {
        $template->delete();
        return redirect()->route('devices.rest-api.templates.index')->with('success', 'Template deleted successfully.');
    }

    /**
     * Internal helper to clean up metric mapping JSON strings and convert them back to PHP arrays.
     * This prevents unwanted whitespace and control characters from polluting the final saved structure.
     */
    protected function cleanTemplateMappings(array $templateData): array
    {
        if (!isset($templateData['connections'])) {
            return $templateData;
        }

        foreach ($templateData['connections'] as &$connection) {
            if (isset($connection['endpoints'])) {
                foreach ($connection['endpoints'] as &$endpoint) {

                    // --- Handle metric_map ---
                    if (isset($endpoint['metric_map'])) {
                        $mapData = $endpoint['metric_map'];

                        if (is_string($mapData)) {
                            // 1. Clean the outer string (removes \r\n\t)
                            $cleanString = trim(str_replace(["\r", "\n", "\t"], '', $mapData));

                            // 2. Decode the clean string into a PHP array
                            $phpArray = json_decode($cleanString, true);

                            // 3. Re-encode the PHP array back to a clean string
                            // This ensures it is saved cleanly to the DB, ready for processing.
                            $endpoint['metric_map'] = json_encode($phpArray, JSON_UNESCAPED_SLASHES);
                        } else {
                            // If it's already a PHP array (e.g., from seeder), ensure it's encoded to a string.
                            $endpoint['metric_map'] = json_encode($mapData, JSON_UNESCAPED_SLASHES);
                        }
                    }

                    // --- Handle response_mapping (if separate) ---
                    if (isset($endpoint['response_mapping'])) {
                        $mapData = $endpoint['response_mapping'];

                        if (is_string($mapData)) {
                            $cleanString = trim(str_replace(["\r", "\n", "\t"], '', $mapData));
                            $phpArray = json_decode($cleanString, true);
                            $endpoint['response_mapping'] = json_encode($phpArray, JSON_UNESCAPED_SLASHES);
                        } else {
                            $endpoint['response_mapping'] = json_encode($mapData, JSON_UNESCAPED_SLASHES);
                        }
                    }
                }
            }
        }

        return $templateData;
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
     * Get session token for session-based authentication
     */
    private function getSessionToken(array $connData, \App\Models\Device $device, $client, bool $verifySsl): ?string
    {
        if (!isset($connData['credential_id'])) {
            return null;
        }

        $credential = \App\Models\RestApiCredential::find($connData['credential_id']);
        if (!$credential || Str::lower($credential->authenticationType->name) !== 'session token') {
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

            $loginMethod = Str::upper($params['login_method'] ?? 'POST');
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
}
