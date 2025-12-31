<?php

/**
 * EditDeviceController.php
 *
 * Controller for device editing, now using device attributes instead of database tables
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @link       https://www.librenms.org
 * @copyright  2025 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Device;

use App\ApiClients\DeviceApiClientFactory;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\PollerGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Exceptions\HostRenameException;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\File;
use LibreNMS\Util\Number;
use App\Http\Controllers\DeviceController;

class EditDeviceController
{
    /**
     * Show the device edit page
     */
    public function index(Device $device): View
    {
        // Eager load attribs to ensure they're available in the view
        $device->load('attribs');

        $section = request()->get('section', 'device');

        // Handle API section
        if ($section === 'api') {
            // Get templates (filtered for this OS) and auth types
            $allTemplates = ApiTemplateManager::getAllTemplates();
            $templates = array_filter($allTemplates, function ($template) use ($device) {
                return empty($template['os']) || in_array($device->os, $template['os'], true);
            });
            $authTypes = ApiTemplateManager::getAuthTypes();

            // Get current API configuration from device attributes
            $apiEnabled = (bool) $device->getAttrib('api_enabled');
            $baseUrl = $device->getAttrib('api_base_url');
            $authType = $device->getAttrib('api_auth_type');
            $selectedTemplate = $device->getAttrib('api_template_key') ?: $device->getAttrib('api_template');

            // Create an apiConfig-like object for compatibility with the blade view
            // The blade view expects $apiConfig to have properties like base_url, verify_ssl, etc.
            $apiConfig = null;
            if ($apiEnabled || $baseUrl) {
                $apiConfig = (object) [
                    'base_url' => $baseUrl,
                    'verify_ssl' => (bool) $device->getAttrib('api_verify_ssl', true),
                    'schema' => $authType ? (object) ['key' => $authType] : null,
                    'extra_headers' => json_decode($device->getAttrib('api_extra_headers', '{}'), true),
                ];
            }

            // Auto-select template for known OSes if no configuration exists
            $autoSelectTemplate = false;
            if (!$selectedTemplate && !$apiConfig && !empty($osTemplates)) {
                // Auto-select the first matching template for this OS
                $selectedTemplate = array_key_first($osTemplates);
                $autoSelectTemplate = true;

                // Also set the auth type from the template for auto-selection
                $defaultTemplate = ApiTemplateManager::loadTemplate($selectedTemplate);
                if ($defaultTemplate && !$authType) {
                    $authType = $defaultTemplate['auth_type'] ?? null;
                }
            }

            // Get saved endpoints from device attributes, or load from template if none saved
            $savedEndpoints = json_decode($device->getAttrib('api_endpoints', '[]'), true) ?: [];

            // If no saved endpoints and we have a template, use template endpoints
            if (empty($savedEndpoints) && $selectedTemplate) {
                $templateEndpoints = ApiTemplateManager::getTemplateEndpoints($selectedTemplate);
                if (!empty($templateEndpoints)) {
                    $savedEndpoints = array_map(function($ep) {
                        return [
                            'name' => $this->generateEndpointName($ep['path'], $ep['capability'] ?? ''),
                            'path' => $ep['path'],
                            'method' => $ep['method'] ?? 'GET',
                            'category' => $ep['capability'] ?? 'general',
                            'enabled' => $ep['enabled'] ?? true,
                            'transform' => $ep['transform'] ?? '',
                            'is_template' => true,
                        ];
                    }, $templateEndpoints);
                }
            }

            return view('device.edit', [
                'device' => $device,
                'section' => 'api',
                'templates' => $osTemplates,
                'authTypes' => $authTypes,
                'apiConfig' => $apiConfig,
                'selectedTemplate' => $selectedTemplate,
                'autoSelectTemplate' => $autoSelectTemplate,
                'savedEndpoints' => $savedEndpoints,
                'defaultAuthType' => $authType,
            ]);
        }

        // Device settings section
        if ($section === 'device') {
            $types = collect(LibrenmsConfig::get('device_types'))->keyBy('type');
            if (! $types->has($device->type)) {
                $types->put($device->type, [
                    'icon' => null,
                    'text' => ucfirst($device->type),
                    'type' => $device->type,
                ]);
            }

            [$rrd_size, $rrd_num] = File::getFolderSize(Rrd::dirFromHost($device->hostname));

            $alertSchedules = $device->alertSchedules()->isActive()->get();
            $isUnderMaintenance = $alertSchedules->isNotEmpty();
            $exclusiveSchedules = $alertSchedules->filter(function ($schedule) {
                $totalMappings = DB::table('alert_schedulables')
                    ->where('schedule_id', $schedule->schedule_id)
                    ->count();

                return $totalMappings === 1;
            });
            $exclusive_schedule_id = $exclusiveSchedules->count() === 1 ? $exclusiveSchedules->first()->schedule_id : 0;

            [$static_show, $static_groups] = DeviceGroup::where('type', 'static')->exists()
                ? [true, $device->groups()->where('type', 'static')->pluck('name', 'id')]
                : [false, []];

            return view('device.edit', [
                'device' => $device,
                'section' => $section,
                'show_static_groups' => $static_show,
                'static_groups' => $static_groups,
                'types' => $types,
                'default_type' => LibrenmsConfig::getOsSetting($device->os, 'type'),
                'parents' => $device->parents()->pluck('hostname', 'device_id'),
                'poller_groups' => PollerGroup::orderBy('group_name')->pluck('group_name', 'id'),
                'default_poller_group' => LibrenmsConfig::get('distributed_poller_group'),
                'override_sysContact_bool' => $device->getAttrib('override_sysContact_bool'),
                'override_sysContact_string' => $device->getAttrib('override_sysContact_string'),
                'maintenance' => $isUnderMaintenance,
                'default_maintenance_behavior' => MaintenanceBehavior::from((int) LibrenmsConfig::get('alert.scheduled_maintenance_default_behavior'))->value,
                'exclusive_maintenance_id' => $exclusive_schedule_id,
                'rrd_size' => Number::formatBi($rrd_size),
                'rrd_num' => $rrd_num,
            ]);
        }

        // Legacy sections fallback
        $deviceController = new DeviceController();
        $legacyContent = $deviceController->renderLegacyTab('edit', $device, ['vars' => ['section' => $section]]);

        return view('device.edit', [
            'device' => $device,
            'section' => $section,
            'legacyContent' => $legacyContent,
        ]);
    }

    /**
     * Update device settings
     */
    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
    {
        Log::info('EditDeviceController@update called', [
            'device_id' => $device->device_id,
            'has_api_settings_form' => $request->has('api_settings_form'),
        ]);

        // Check if this is an API settings update
        if ($request->has('api_settings_form')) {
            $this->updateApiSettings($request, $device);

            // Reload the device to get fresh attributes
            $device->refresh();
            $device->load('attribs');

            toast()->success(__('Device API settings updated'));

            return redirect()->route('device.edit', ['device' => $device->device_id, 'section' => 'api']);
        }

        // Handle device settings update
        $device->fill($request->validated());

        $device->parents()->sync($request->get('parent_id', []));

        // sync groups without removing dynamic groups
        $dynamic_groups = $device->groups()->where('type', 'dynamic')->pluck('id')->toArray();
        $device->groups()->sync(array_merge($dynamic_groups, $request->get('static_groups', [])));

        // handle sysLocation update
        if ($device->override_sysLocation) {
            $device->setLocation($request->get('sysLocation'), true, true);
            $device->location?->save();
        } elseif ($device->isDirty('override_sysLocation')) {
            $device->location()->dissociate();
        }

        // check if sysContact is overridden
        if ($request->get('override_sysContact')) {
            $device->setAttrib('override_sysContact_bool', true);
            $device->setAttrib('override_sysContact_string', (string) $request->get('override_sysContact_string'));
        } else {
            $device->forgetAttrib('override_sysContact_bool');
        }

        // check if type was overridden
        if ($device->isDirty('type')) {
            $device->type = strtolower($device->type);
            $device->setAttrib('override_device_type', true);
        }

        // save it
        try {
            if ($device->isDirty()) {
                if ($device->save()) {
                    toast()->success(__('Device record updated'));
                } else {
                    toast()->error(__('Device record update error'));
                }
            }
        } catch (HostRenameException $e) {
            toast()->error($e->getMessage());
        }

        return response()->redirectToRoute('device', ['device' => $device->device_id, 'edit']);
    }

    /**
     * Update API settings (stored in device attributes)
     */
        private function updateApiSettings(Request $request, Device $device): void
    {
        // Check if API is being disabled
        if (!$request->boolean('api_enabled')) {
            // Clear all API-related attributes
            $this->clearApiAttributes($device);
            toast()->success(__('API configuration disabled'));
            return;
        }

        // Validate required fields
        $baseUrl = trim((string) $request->input('api_base_url'));
        if (empty($baseUrl)) {
            toast()->error(__('Base URL is required'));
            return;
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            toast()->error(__('Invalid base URL'));
            return;
        }

        $authType = $request->input('api_auth_type', 'token');
        $templateKey = $this->resolveTemplateKey($device, $request);
        if (!$templateKey) {
            toast()->error(__('An API template is required to enable REST polling'));
            return;
        }

        // Save basic settings
        $device->setAttrib('api_enabled', true);
        $device->setAttrib('api_base_url', $baseUrl);
        $device->setAttrib('api_auth_type', $authType);
        $device->setAttrib('api_template_key', $templateKey);
        $device->setAttrib('api_verify_ssl', $request->boolean('api_verify_ssl', true));
        $device->setAttrib('api_timeout_ms', (int) $request->input('api_timeout_ms', 10000));

        // Save credentials based on auth type
        $this->saveCredentials($device, $authType, $request);

        // Save vendor-specific fields if present
        $this->saveVendorSpecificFields($device, $request);

        // Save endpoints (custom or template defaults)
        $endpoints = $this->resolveEndpointsForDevice($templateKey, $request);
        $device->setAttrib('api_endpoints', json_encode($endpoints));
        $device->setAttrib('rest_endpoints', json_encode($endpoints));

        Log::info('API settings saved to device attributes', [
            'device_id' => $device->device_id,
            'base_url' => $baseUrl,
            'auth_type' => $authType,
            'template_key' => $templateKey,
            'endpoint_count' => count($endpoints),
        ]);
    }

    private function resolveTemplateKey(Device $device, ?Request $request = null): ?string
    {
        $templateKey = $request?->input('api_template_key', $request?->input('api_template'));
        if ($templateKey) {
            return $templateKey;
        }

        // Reuse any existing template selection when re-saving without changes
        $existingTemplateKey = $device->getAttrib('api_template_key') ?: $device->getAttrib('api_template');
        if ($existingTemplateKey) {
            return $existingTemplateKey;
        }

        $allTemplates = ApiTemplateManager::getAllTemplates();
        $templates = array_filter($allTemplates, function ($template) use ($device) {
            return empty($template['os']) || in_array($device->os, $template['os'], true);
        });

        return count($templates) === 1 ? array_key_first($templates) : null;
    }

    private function resolveEndpointsForDevice(string $templateKey, Request $request): array
    {
        $rawJson = $request->input('rest_endpoints', '[]');
        $decoded = json_decode($rawJson, true);

        $endpoints = is_array($decoded) ? $decoded : [];

        if (empty($endpoints)) {
            $template = ApiTemplateManager::loadTemplate($templateKey) ?? [];
            $templateEndpoints = $template['endpoints'] ?? [];

            $endpoints = array_map(fn ($endpoint) => $this->normalizeEndpoint($endpoint), $templateEndpoints);
        } else {
            $endpoints = array_map(fn ($endpoint) => $this->normalizeEndpoint($endpoint), $endpoints);
        }

        // Remove any endpoints that failed normalization (e.g., missing path)
        return array_values(array_filter($endpoints));
    }

    private function normalizeEndpoint(array $endpoint): ?array
    {
        $path = trim((string) ($endpoint['path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $method = strtoupper((string) ($endpoint['method'] ?? 'GET'));

        return [
            'name' => $endpoint['name'] ?? ($endpoint['capability'] ?? 'endpoint') . ' ' . $path,
            'path' => $path,
            'method' => $method,
            'category' => $endpoint['category'] ?? ($endpoint['capability'] ?? 'general'),
            'poll_interval' => (int) ($endpoint['poll_interval'] ?? 300),
            'enabled' => array_key_exists('enabled', $endpoint) ? (bool) $endpoint['enabled'] : true,
            'transform' => $endpoint['transform'] ?? ($endpoint['transform_map'] ?? ''),
            'headers' => $endpoint['headers'] ?? [],
            'request_body' => $endpoint['request_body'] ?? null,
        ];
    }

    /**
     * Save credentials (encrypted) based on auth type
     */
    private function saveCredentials(Device $device, string $authType, Request $request): void
    {
        // Clear all credential fields first
        $credentialFields = [
            'api_credential_username',
            'api_credential_password',
            'api_credential_api_token',
            'api_credential_access_token',
            'api_credential_token_user',
            'api_credential_token_id',
            'api_credential_token_secret',
            'api_credential_hostname',
            'api_credential_enterprise_id',
            'api_credential_edge_id',
        ];

        foreach ($credentialFields as $field) {
            $device->forgetAttrib($field);
        }

        // Helper to get value from either api_ or rest_ or direct field name
        $getValue = function (string $field) use ($request) {
            // Try various prefixes/naming conventions
            $names = [
                $field,
                'api_' . $field,
                'api_credential_' . $field,
                str_replace('_', '', $field), // e.g., apitoken -> api_token
            ];
            foreach ($names as $name) {
                if ($request->filled($name)) {
                    return $request->input($name);
                }
            }
            return null;
        };

        // Save new credentials based on auth type
        switch ($authType) {
            case 'basic':
            case 'session':
            case 'esxi_soap':
            case 'cisco_ucsm_xml':
                $username = $getValue('username') ?: $getValue('credential_username');
                $password = $getValue('password') ?: $getValue('credential_password');
                if ($username) {
                    $device->setAttrib('api_credential_username', $username);
                }
                if ($password) {
                    $device->setAttrib('api_credential_password', Crypt::encryptString($password));
                }
                break;

            case 'token':
            case 'bearer':
            case 'purestorage_api_token_login':
                // Try multiple field names for the token
                $token = $getValue('token') ?: $getValue('api_token') ?: $getValue('access_token') ?: $getValue('credential_api_token');
                if ($token) {
                    $device->setAttrib('api_credential_api_token', Crypt::encryptString($token));
                }
                break;

            case 'proxmox_token':
                $tokenUser = $getValue('token_user') ?: $getValue('credential_token_user');
                $tokenId = $getValue('token_id') ?: $getValue('credential_token_id');
                $tokenSecret = $getValue('token_secret') ?: $getValue('credential_token_secret');
                if ($tokenUser) {
                    $device->setAttrib('api_credential_token_user', $tokenUser);
                }
                if ($tokenId) {
                    $device->setAttrib('api_credential_token_id', $tokenId);
                }
                if ($tokenSecret) {
                    $device->setAttrib('api_credential_token_secret', Crypt::encryptString($tokenSecret));
                }
                break;

            case 'vmware_soap':
                $hostname = $getValue('hostname') ?: $getValue('credential_hostname');
                $username = $getValue('username') ?: $getValue('credential_username');
                $password = $getValue('password') ?: $getValue('credential_password');
                if ($hostname) {
                    $device->setAttrib('api_credential_hostname', $hostname);
                }
                if ($username) {
                    $device->setAttrib('api_credential_username', $username);
                }
                if ($password) {
                    $device->setAttrib('api_credential_password', Crypt::encryptString($password));
                }
                break;

            case 'vmware_velocloud_token':
                $username = $getValue('username') ?: $getValue('credential_username');
                $password = $getValue('password') ?: $getValue('credential_password');
                $token = $getValue('token') ?: $getValue('api_token') ?: $getValue('credential_api_token');
                $enterpriseId = $getValue('enterprise_id') ?: $getValue('credential_enterprise_id');
                $edgeId = $getValue('edge_id') ?: $getValue('credential_edge_id');
                if ($username) {
                    $device->setAttrib('api_credential_username', $username);
                }
                if ($password) {
                    $device->setAttrib('api_credential_password', Crypt::encryptString($password));
                }
                if ($token) {
                    $device->setAttrib('api_credential_api_token', Crypt::encryptString($token));
                }
                if ($enterpriseId) {
                    $device->setAttrib('api_credential_enterprise_id', $enterpriseId);
                }
                if ($edgeId) {
                    $device->setAttrib('api_credential_edge_id', $edgeId);
                }
                break;

            case 'cisco_ftd_oauth':
            case 'oauth2':
                $username = $getValue('username') ?: $getValue('credential_username') ?: $getValue('client_id');
                $password = $getValue('password') ?: $getValue('credential_password') ?: $getValue('client_secret');
                if ($username) {
                    $device->setAttrib('api_credential_username', $username);
                }
                if ($password) {
                    $device->setAttrib('api_credential_password', Crypt::encryptString($password));
                }
                break;
        }
    }

    /**
     * Save vendor-specific fields
     */
    private function saveVendorSpecificFields(Device $device, Request $request): void
    {
        // VeloCloud specific
        if ($request->filled('api_enterprise_id')) {
            $device->setAttrib('api_credential_enterprise_id', $request->input('api_enterprise_id'));
        }
        if ($request->filled('api_edge_id')) {
            $device->setAttrib('api_credential_edge_id', $request->input('api_edge_id'));
        }

        // Pure Storage specific
        if ($request->filled('api_auth_header_name')) {
            $device->setAttrib('api_credential_auth_header_name', $request->input('api_auth_header_name'));
        }
        if ($request->filled('api_login_path')) {
            $device->setAttrib('api_credential_login_path', $request->input('api_login_path'));
        }

        // Proxmox specific (auth schema)
        if ($request->filled('api_auth_schema')) {
            $device->setAttrib('api_auth_schema', $request->input('api_auth_schema'));
        }
    }

    /**
     * Generate a human-readable name from an endpoint path
     */
    private function generateEndpointName(string $path, string $capability = ''): string
    {
        $name = ltrim($path, '/');
        $name = preg_replace('/\{[^}]+\}/', '', $name); // Remove {variables}
        $name = str_replace(['/', '_', '-'], ' ', $name);
        $name = trim($name);
        $name = ucwords($name);

        if ($capability) {
            $name = ucfirst($capability) . ': ' . $name;
        }

        return $name ?: 'API Endpoint';
    }

    /**
     * Clear all API-related attributes
     */
    private function clearApiAttributes(Device $device): void
    {
        $apiAttributes = [
            'api_enabled',
            'api_base_url',
            'api_template',
            'api_template_key',
            'api_auth_type',
            'api_auth_schema',
            'api_verify_ssl',
            'api_timeout_ms',
            'api_template',
            'api_template_key',
            'api_credential_username',
            'api_credential_password',
            'api_credential_api_token',
            'api_credential_access_token',
            'api_credential_token_user',
            'api_credential_token_id',
            'api_credential_token_secret',
            'api_credential_hostname',
            'api_credential_enterprise_id',
            'api_credential_edge_id',
            'api_credential_auth_header_name',
            'api_credential_login_path',
            'api_auth_schema',
            'api_endpoints',
            'rest_endpoints',
        ];

        foreach ($apiAttributes as $attr) {
            $device->forgetAttrib($attr);
        }
    }

    /**
     * Test API connection
     */
    public function testConnection(Request $request, Device $device): JsonResponse
    {
        try {
            // Temporarily save credentials for testing
            $tempDevice = clone $device;
            $this->saveCredentials($tempDevice, $request->input('api_auth_type', 'token'), $request);
            $tempDevice->setAttrib('api_base_url', $request->input('api_base_url'));
            $templateKey = $this->resolveTemplateKey($tempDevice, $request);
            if (!$templateKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'An API template is required to test the connection',
                ]);
            }
            $tempDevice->setAttrib('api_template_key', $templateKey);
            $tempDevice->setAttrib('api_verify_ssl', $request->boolean('api_verify_ssl', true));
            $tempDevice->setAttrib('api_timeout_ms', (int) $request->input('api_timeout_ms', 10000));

            // Try to create API client and test connection
            $client = DeviceApiClientFactory::make($tempDevice);

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'No API client available for this device OS',
                ]);
            }

            $isReachable = method_exists($client, 'isReachable') ? $client->isReachable() : true;

            if ($isReachable) {
                return response()->json([
                    'success' => true,
                    'message' => 'API connection successful',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'API connection failed - host not reachable',
            ]);
        } catch (\Exception $e) {
            Log::error('API connection test failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset circuit breaker for API polling
     */
    public function resetCircuitBreaker(Request $request, Device $device): JsonResponse
    {
        try {
            // Reset error tracking attributes
            $device->forgetAttrib('api_error_count');
            $device->forgetAttrib('api_last_error');
            $device->forgetAttrib('api_last_error_message');
            $device->forgetAttrib('api_circuit_open');

            return response()->json([
                'success' => true,
                'message' => 'Circuit breaker reset successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset circuit breaker: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Toggle endpoint (deprecated - endpoints now managed in OS classes)
     */
    public function toggleEndpoint(Request $request, Device $device): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Endpoint management has moved to OS classes and normalizers',
        ]);
    }

    /**
     * Update endpoint (deprecated - endpoints now managed in OS classes)
     */
    public function updateEndpoint(Request $request, Device $device): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Endpoint management has moved to OS classes and normalizers',
        ]);
    }

    /**
     * Get auth type presets based on device OS
     */
    private function getAuthTypePresetsForOS(string $os): array
    {
        $presets = [
            'vmware-vcsa' => [
                ['value' => 'session', 'label' => 'vCenter Session Auth (Recommended)', 'default' => true],
                ['value' => 'basic', 'label' => 'Basic Authentication'],
            ],
            'vmware-esxi' => [
                ['value' => 'vmware_soap', 'label' => 'ESXi SOAP Auth (Recommended)', 'default' => true],
            ],
            'proxmox' => [
                ['value' => 'proxmox_token', 'label' => 'Proxmox API Token (Recommended)', 'default' => true],
                ['value' => 'session', 'label' => 'Proxmox Ticket Auth'],
            ],
            'purestorage' => [
                ['value' => 'token', 'label' => 'Pure Storage API Token (Recommended)', 'default' => true],
            ],
            'fortigate' => [
                ['value' => 'bearer', 'label' => 'FortiGate API Token (Recommended)', 'default' => true],
            ],
            'velocloud' => [
                ['value' => 'token', 'label' => 'VeloCloud API Token (Recommended)', 'default' => true],
                ['value' => 'session', 'label' => 'VeloCloud Username/Password'],
            ],
        ];

        // Return OS-specific presets or generic defaults
        return $presets[$os] ?? [
            ['value' => 'token', 'label' => 'API Token / Bearer Token', 'default' => true],
            ['value' => 'basic', 'label' => 'Basic Authentication'],
            ['value' => 'session', 'label' => 'Session-based Authentication'],
        ];
    }
}
