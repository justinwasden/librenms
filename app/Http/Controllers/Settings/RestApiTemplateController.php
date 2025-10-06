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

        if (is_string($validated['template_data'])) {
            $validated['template_data'] = json_decode($validated['template_data'], true);
        }

        $validated['template_data'] = $this->cleanTemplateMappings($validated['template_data']);

        // Normalize booleans and add missing resource_type
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

                        if (empty($endpoint['resource_type'])) {
                            $endpoint['resource_type'] = $endpoint['name'] ?? 'unknown';
                        }

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
            ->route('devices.rest-api.templates.edit', $template->id)
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
        array_walk_recursive($data, function (&$value) use ($device) {
            if (is_string($value)) {
                $value = $this->replacePlaceholdersInString($value, $device);
            }
        });

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
