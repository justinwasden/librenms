<?php

/**
 * EditDeviceController.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2025 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Device;

use App\ApiClients\DeviceHttpClient;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\Models\DeviceGroup;
use App\Models\PollerGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Exceptions\HostRenameException;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;
use LibreNMS\Util\File;
use LibreNMS\Util\Number;
use App\Http\Controllers\DeviceController;

class EditDeviceController
{
    public function index(Device $device): View
    {
        // Eager load attribs to ensure they're available in the view
        $device->load('attribs');

        $section = request()->get('section', 'device');

        // Handle API section (Renders the blade partial)
        if ($section === 'api') {
            $templates = ApiTemplateManager::getTemplatesForOs($device->os);
            $authTypes = ApiTemplateManager::getAuthTypes();
            $apiConfig = DeviceApiConfig::with(['schema.fields', 'template'])
                ->where('device_id', $device->device_id)
                ->first();
            $selectedTemplate = $apiConfig?->template?->key ?? null;
            if (!$selectedTemplate && count($templates) === 1) {
                $selectedTemplate = array_key_first($templates);
            }
            $templateData = $selectedTemplate ? ApiTemplateManager::loadTemplate($selectedTemplate) : null;

            return view('device.edit', [
                'device' => $device,
                'section' => 'api',
                'templates' => $templates,
                'authTypes' => $authTypes,
                'apiConfig' => $apiConfig,
                'selectedTemplate' => $selectedTemplate,
                'templateData' => $templateData,
                'autoSelectTemplate' => !$apiConfig && count($templates) === 1,
            ]);
        }

        // Handle the primary 'device' settings tab (currently Blade-based partial)
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

                return $totalMappings === 1; // only exclusive schedules
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

        // Handle all other legacy sections using the legacy renderer.
        // This allows them to render within the new Blade layout.
        $deviceController = new DeviceController();
        $legacyContent = $deviceController->renderLegacyTab('edit', $device, ['vars' => ['section' => $section]]);

        return view('device.edit', [
            'device' => $device,
            'section' => $section,
            'legacyContent' => $legacyContent,
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
    {
        // Check if this is an API settings update
        if ($request->has('rest_enabled')) {
            $this->updateApiSettings($request, $device);

            // Reload the device to get fresh attributes from database
            $device->refresh();
            $device->load('attribs');

            toast()->success(__('Device API settings updated'));

            return redirect()->route('device.edit', ['device' => $device->device_id, 'section' => 'api']);
        }

        // Handle device settings update
        $device->fill($request->validated());

        $device->parents()->sync($request->get('parent_id', [])); // TODO avoid loops!

        // sync groups without removing dynamic groups
        $dynamic_groups = $device->groups()->where('type', 'dynamic')->pluck('id')->toArray();
        $device->groups()->sync(array_merge($dynamic_groups, $request->get('static_groups', [])));

        // handle sysLocation update
        if ($device->override_sysLocation) {
            $device->setLocation($request->get('sysLocation'), true, true);
            $device->location?->save();
        } elseif ($device->isDirty('override_sysLocation')) {
            // no longer overridden, clear location
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

        // save it, no message if no changes
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

    private function updateApiSettings($request, Device $device): void
    {
        // Check if API is being disabled
        if (!$request->boolean('rest_enabled')) {
            DeviceApiConfig::where('device_id', $device->device_id)->delete();

            // Also clear REST attribs
            $device->forgetAttrib('rest_enabled');
            $device->forgetAttrib('rest_template_key');
            $device->forgetAttrib('rest_auth_type');
            $device->forgetAttrib('rest_base_url');
            $device->forgetAttrib('rest_headers');
            $device->forgetAttrib('rest_verify_tls');
            $device->forgetAttrib('rest_endpoints');
            $device->forgetAttrib('rest_timeout_ms');
            $device->forgetAttrib('rest_proxy');

            return;
        }

        // Get template and schema IDs
        $templateKey = $request->input('rest_template');
        $authTypeKey = $request->input('rest_auth_type');

        if (!$templateKey || !$authTypeKey) {
            toast()->error(__('Template and authentication type are required'));
            return;
        }

        $template = \App\Models\DeviceApiTemplate::where('key', $templateKey)->first();
        $schema = \App\Models\DeviceApiAuthSchema::where('key', $authTypeKey)->first();

        if (!$template || !$schema) {
            toast()->error(__('Selected template or authentication schema not found'));
            return;
        }

        // Persist selected template and auth type to device attribs
        $device->setAttrib('rest_template_key', $template->key);
        $device->setAttrib('rest_auth_type', $schema->key);
        $device->setAttrib('rest_enabled', 1);

        // Base URL override from form or resolve from template pattern
        $overrideBase = trim((string) $request->input('rest_base_url'));
        if (!empty($overrideBase)) {
            $overrideBase = rtrim($overrideBase, '/');
            // basic URL validation
            if (! filter_var($overrideBase, FILTER_VALIDATE_URL)) {
                toast()->error(__('Invalid base URL'));
                return;
            }
            $device->setAttrib('rest_base_url', $overrideBase);
        } else {
            // Resolve and persist base_url from template's base_url_pattern
            DeviceApiSettings::ensureResolvedBaseUrl($device);
        }

        // Persist TLS verification and connection options in device attribs for httpOptions()
        $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0);
        $device->setAttrib('rest_timeout_ms', (int) $request->input('rest_timeout_ms', 5000));
        $device->setAttrib('rest_proxy', (string) $request->input('rest_proxy', ''));

        // Save endpoints configuration (as JSON string)
        if ($request->has('rest_endpoints')) {
            $endpoints = $request->input('rest_endpoints');
            if (is_string($endpoints)) {
                $device->setAttrib('rest_endpoints', $endpoints);
            } elseif (is_array($endpoints)) {
                $device->setAttrib('rest_endpoints', json_encode($endpoints));
            }
        }

        // Parse extra headers (one per line "Header: value"), minimal validation
        $headersString = (string) $request->input('rest_headers', '');
        $extraHeaders = [];
        if (!empty($headersString)) {
            foreach (explode("\n", $headersString) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue; // reject invalid lines
                }
                [$name, $value] = array_map('trim', explode(':', $line, 2));
                if ($name === '') {
                    continue;
                }
                // coalesce duplicates by last write
                $extraHeaders[$name] = $value;
            }
        }

        // Persist headers to device attribs as JSON for DeviceApiSettings::httpOptions()
        $device->setAttrib('rest_headers', json_encode($extraHeaders));

        // Ensure base url is resolved after setting rest_template_key
        DeviceApiSettings::ensureResolvedBaseUrl($device);

        // Persist DeviceApiConfig row (for UI/config viewing)
        $apiConfig = DeviceApiConfig::firstOrNew([
            'device_id' => $device->device_id,
        ]);

        // Detect schema change for password field handling
        $schemaChanged = $apiConfig->schema_id !== $schema->id;

        // Update config fields
        $apiConfig->template_id = $template->id;
        $apiConfig->schema_id = $schema->id;
        $apiConfig->base_url = $request->input('rest_base_url') ?? ($device->getAttrib('rest_base_url') ?? '');
        $apiConfig->verify_ssl = $request->has('rest_verify_tls');
        $apiConfig->extra_headers = $extraHeaders;

        // Save auth values dynamically from schema fields
        foreach ($schema->fields as $field) {
            $fieldName = $field->name;
            $inputValue = $request->input($fieldName);

            if ($field->type === 'password') {
                if ($request->filled($fieldName)) {
                    $apiConfig->setValue($fieldName, $inputValue);
                } elseif ($schemaChanged) {
                    $apiConfig->setValue($fieldName, null);
                }
            } else {
                $apiConfig->setValue($fieldName, $inputValue);
            }
        }

        $apiConfig->save();

        // Notify user if schema changed and password fields were cleared
        if ($schemaChanged) {
            toast()->info(__('Authentication schema changed; please re-enter secrets if required.'));
        }
    }

    public function testConnection(Request $request, Device $device): JsonResponse
    {
        try {
            $baseUrl = $request->input('rest_base_url');
            $templateKey = $request->input('rest_template');
            $authType = $request->input('rest_auth_type');

            // Validate required fields
            if (empty($baseUrl)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Base URL is required',
                ], 400);
            }

            if (empty($authType)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Authentication type is required',
                ], 400);
            }

            // Load template if provided to get test endpoint
            $template = null;
            $testPath = '/';

            if (!empty($templateKey)) {
                $template = ApiTemplateManager::loadTemplate($templateKey);
                if (!$template) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'Template not found',
                    ], 404);
                }

                // Pick first endpoint from template
                if (!empty($template['endpoints'])) {
                    $testPath = $template['endpoints'][0]['path'] ?? '/';
                }
            }

            // Build test options from request
            $options = [
                'base_url' => $baseUrl,
                'verify_tls' => $request->boolean('rest_verify_tls', true),
                'timeout_ms' => (int) $request->input('rest_timeout_ms', 5000),
                'headers' => [],
                'enable_circuit_breaker' => false,
                'max_retries' => 0,
            ];

            // Add auth headers based on type and get field values from request
            $schema = \App\Models\DeviceApiAuthSchema::where('key', $authType)->with('fields')->first();
            if ($schema) {
                foreach ($schema->fields as $field) {
                    $value = $request->input($field->name);
                    if ($value) {
                        // Add auth based on schema type
                        if ($authType === 'bearer' && $field->name === 'api_token') {
                            $options['headers']['Authorization'] = 'Bearer ' . $value;
                        } elseif ($authType === 'apikey' && $field->name === 'api_key') {
                            $options['headers']['X-API-Key'] = $value;
                        } elseif ($authType === 'basic') {
                            if ($field->name === 'username') {
                                $username = $value;
                            } elseif ($field->name === 'password') {
                                $password = $value ?? '';
                                if (isset($username)) {
                                    $options['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
                                }
                            }
                        }
                    }
                }
            }

            // Create client and test
            $client = new \App\ApiClients\DeviceHttpClient($options);

            $start = microtime(true);
            $data = $client->get($testPath);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return response()->json([
                'ok' => true,
                'success' => true,
                'message' => 'Connection successful',
                'test_path' => $testPath,
                'latency_ms' => $latencyMs,
            ]);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            // Friendly 4xx handling: treat as connected
            if (preg_match('/returned\s+(\d{3})/', $msg, $m)) {
                $code = (int) $m[1];
                if ($code >= 400 && $code < 500) {
                    $messages = [
                        401 => 'Connection successful - Authentication required (check credentials)',
                        403 => 'Connection successful - Authenticated but insufficient permissions',
                        404 => 'Connection successful - Endpoint not found (expected for some APIs)',
                    ];
                    return response()->json([
                        'ok' => true,
                        'success' => true,
                        'message' => $messages[$code] ?? "Connection successful (HTTP $code)",
                        'http_code' => $code,
                    ]);
                }
            }

            // Provide helpful error messages
            if (str_contains($msg, 'Could not resolve host')) {
                $msg = 'Could not resolve hostname - check the URL';
            } elseif (str_contains($msg, 'Connection refused')) {
                $msg = 'Connection refused - check if the service is running';
            } elseif (str_contains($msg, 'timed out')) {
                $msg = 'Connection timed out - check firewall/network settings';
            } elseif (str_contains($msg, 'SSL')) {
                $msg .= ' (Try disabling SSL verification for testing)';
            }

            return response()->json([
                'ok' => false,
                'error' => $msg,
            ], 400);
        }
    }

    protected function makeClient(Device $device, array $tpl)
		{
		    return match ($tpl['vendor']) {
		        'proxmox_ve_token', 'proxmox_ve_ticket' => new \App\ApiClients\Proxmox\ProxmoxApiClient($device),
		        'purestorage_flasharray' => new \App\ApiClients\PureStorage\FlashArrayClient($device, ['strategy_key' => $tpl['auth_type']]),
		        'vmware_vcenter', 'vmware_vcenter_default' => new \App\ApiClients\Vmware\VcenterClient($device),
		        'vmware_esxi' => new \App\ApiClients\Vmware\EsxiClient($device),

		        'fortinet_fortigate' => new \App\ApiClients\Generic\RestClient($device),
		        'juniper_junos' => new \App\ApiClients\Generic\RestClient($device),
		        'dell_os10' => new \App\ApiClients\Generic\RestClient($device),
		        'hpe_network' => new \App\ApiClients\Generic\RestClient($device),
		        'hpe_nimble' => new \App\ApiClients\Generic\RestClient($device),
		        'nutanix_prism' => new \App\ApiClients\Generic\RestClient($device),
		        'cisco_ise' => new \App\ApiClients\Generic\RestClient($device),
		        'paloalto_panos' => new \App\ApiClients\Generic\RestClient($device),
		        'cisco_nxos' => new \App\ApiClients\Generic\RestClient($device),
		        'cisco_ios_xr' => new \App\ApiClients\Generic\RestClient($device),
		        'cisco_cucm' => new \App\ApiClients\Generic\RestClient($device),
		        'calix_generic' => new \App\ApiClients\Generic\RestClient($device),
		        'cisco_ndfc' => new \App\ApiClients\Generic\RestClient($device),
		        'arista_eos' => new \App\ApiClients\Generic\RestClient($device),
		        'extreme_exos' => new \App\ApiClients\Generic\RestClient($device),
		        'brocade_fastiron' => new \App\ApiClients\Generic\RestClient($device),
		        'sonicwall_gen7' => new \App\ApiClients\Generic\RestClient($device),
		        'checkpoint_mgmt' => new \App\ApiClients\Generic\RestClient($device),

		        default => new \App\ApiClients\Generic\RestClient($device),
		    };
		}

    public function resetCircuitBreaker(Request $request, Device $device): JsonResponse
    {
        try {
            DeviceApiSettings::resetCircuitBreaker($device);

            return response()->json([
                'success' => true,
                'message' => 'Circuit breaker reset successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function showApiConfig(Device $device)
    {
        $os = $device->os ?? 'generic';
        $templates = ApiTemplateManager::getTemplatesForOs($os);

        $recommended = reset($templates); // first candidate
        // Autofill Pure defaults if recommended is Pure
        $defaults = $recommended['vendor'] === 'purestorage_flasharray'
            ? ['login_path' => '/login', 'auth_header_name' => 'X-Auth-Token']
            : [];

        return view('devices.api-config', [
            'device' => $device,
            'templates' => $templates,
            'recommended' => $recommended,
            'defaults' => $defaults,
        ]);
    }

    public function edit(Device $device, Request $request)
    {
        return $this->index($device);
    }
}