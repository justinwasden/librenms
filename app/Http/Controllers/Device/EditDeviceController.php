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
				    $selectedAuthType = $apiConfig?->schema?->key ?? null;

				    if (!$selectedTemplate && count($templates) === 1) {
				        $selectedTemplate = array_key_first($templates);
				    }


//				    // -------------------------------------------------
//				    // Force defaults for known OSes when enabling API
//				    // -------------------------------------------------
//				    if (!$apiConfig) {
//				        switch ($device->os) {
//				            case 'proxmox':
//				                $selectedTemplate = 'proxmox_ve_token';
//				                $selectedAuthType = 'proxmox_ve_token';
//				                break;
//
//				            case 'purestorage_flasharray':
//				                $selectedTemplate = 'purestorage_flasharray';
//				                $selectedAuthType = 'api-token';
//				                $baseUrl = "https://{$device->hostname}/api/2.26";
//				                $extraDefaults = [
//				                    'login_path' => '/login',
//				                    'auth_header_name' => 'X-Auth-Token',
//				                ];
//				                break;
//				        }
//				    }
//
//					  // --------------------------------------------
//				    // Force default template/auth for Proxmox VE
//				    // --------------------------------------------
//				    if ($device->os === 'proxmox' && !$selectedTemplate) {
//				        $selectedTemplate = 'proxmox_ve_token'; // default template key
//				        $selectedAuthType = 'proxmox_ve_token';  // default auth key
//				    }
//
//				    // -----------------------------
				    // Suppress output from template
				    // -----------------------------
				    $templateData = null;
				    if ($selectedTemplate) {
				        ob_start(); // start output buffering
				        $templateData = ApiTemplateManager::loadTemplate($selectedTemplate);
				        ob_end_clean(); // discard any echoed messages
				    }

				    return view('device.edit', [
				        'device' => $device,
				        'section' => 'api',
				        'templates' => $templates,
				        'authTypes' => $authTypes,
				        'apiConfig' => $apiConfig,
				        'selectedTemplate' => $selectedTemplate,
				        'templateData' => $templateData,
				        'autoSelectTemplate' => !$apiConfig && count($templates) === 1,
			        'savedEndpoints' => \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)
			            ->ordered()
			            ->get()
			            ->map(fn($ep) => [
			                'id' => $ep->id,
			                'name' => $ep->name,
			                'path' => $ep->path,
			                'method' => $ep->method,
			                'category' => $ep->capability,
			                'poll_interval' => $ep->poll_interval,
			                'enabled' => $ep->enabled,
			                'transform' => $ep->transform,
			                'headers' => $ep->headers,
			                'request_body' => $ep->request_body,
			            ])
			            ->toArray(),
				    ]);
				}


		    // ---------------------------
		    // Device settings section
		    // ---------------------------
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

		    // Legacy sections fallback
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
        \Illuminate\Support\Facades\Log::info('EditDeviceController@update called', [
            'device_id' => $device->device_id,
            'has_api_settings_form' => $request->has('api_settings_form'),
            'all_keys' => array_keys($request->all()),
        ]);

        // Check if this is an API settings update (using hidden field to detect form submission)
        if ($request->has('api_settings_form')) {
            \Illuminate\Support\Facades\Log::info('Processing API settings update');
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
            // Delete API configuration and all related data
            $deleted = DeviceApiConfig::where('device_id', $device->device_id)->delete();

            // Clear legacy attribs if they exist (migration cleanup)
            $device->forgetAttrib('rest_enabled');
            $device->forgetAttrib('rest_template_key');
            $device->forgetAttrib('rest_auth_type');
            $device->forgetAttrib('rest_base_url');
            $device->forgetAttrib('rest_headers');
            $device->forgetAttrib('rest_verify_tls');
            $device->forgetAttrib('rest_endpoints');
            $device->forgetAttrib('rest_timeout_ms');
            $device->forgetAttrib('rest_proxy');
            $device->forgetAttrib('rest_token');
            $device->forgetAttrib('rest_token_enc');
            $device->forgetAttrib('rest_username');
            $device->forgetAttrib('rest_password');
            $device->forgetAttrib('rest_password_enc');
            $device->forgetAttrib('proxmox_base_url');
            $device->forgetAttrib('proxmox_token_user');
            $device->forgetAttrib('proxmox_token_id');
            $device->forgetAttrib('proxmox_token');
            $device->forgetAttrib('proxmox_token_enc');
            $device->forgetAttrib('proxmox_username');
            $device->forgetAttrib('proxmox_password_enc');
            $device->forgetAttrib('proxmox_verify_tls');
            $device->forgetAttrib('proxmox_timeout_ms');
            $device->forgetAttrib('proxmox_proxy');
            $device->forgetAttrib('rest_last_success');
            $device->forgetAttrib('rest_last_error');
            $device->forgetAttrib('rest_last_error_message');
            $device->forgetAttrib('rest_error_count');
            $device->forgetAttrib('rest_avg_latency_ms');

            if ($deleted > 0) {
                toast()->success(__('API configuration removed and credentials deleted'));
            }

            return;
        }

        // Get template and schema IDs
        $templateKey = $request->input('rest_template');
        $authTypeKey = $request->input('rest_auth_type');

        // Auth type is required, template is optional
        if (!$authTypeKey) {
            toast()->error(__('Authentication type is required'));
            return;
        }

        $schema = \App\Models\DeviceApiAuthSchema::with('fields')->where('key', $authTypeKey)->first();
        if (!$schema) {
            toast()->error(__('Selected authentication schema not found'));
            return;
        }

        // Template is optional
        $template = null;
        if ($templateKey) {
            $template = \App\Models\DeviceApiTemplate::where('key', $templateKey)->first();
            if (!$template) {
                toast()->error(__('Selected template not found'));
                return;
            }
        }

        // Base URL validation
        $baseUrl = trim((string) $request->input('rest_base_url'));
        if (empty($baseUrl)) {
            toast()->error(__('Base URL is required'));
            return;
        }
        $baseUrl = rtrim($baseUrl, '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            toast()->error(__('Invalid base URL'));
            return;
        }

        // Parse extra headers (one per line "Header: value")
        $headersString = (string) $request->input('rest_headers', '');
        $extraHeaders = [];
        if (!empty($headersString)) {
            foreach (explode("\n", $headersString) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = array_map('trim', explode(':', $line, 2));
                if ($name !== '') {
                    $extraHeaders[$name] = $value;
                }
            }
        }

        // Create or update DeviceApiConfig
        $apiConfig = DeviceApiConfig::firstOrNew([
            'device_id' => $device->device_id,
        ]);

        // Detect schema change for password field handling
        $schemaChanged = $apiConfig->schema_id !== $schema->id;

        // Update config fields
        $apiConfig->template_id = $template?->id;
        $apiConfig->schema_id = $schema->id;
        $apiConfig->base_url = $baseUrl;
        $apiConfig->verify_ssl = $request->boolean('rest_verify_tls');
        $apiConfig->extra_headers = $extraHeaders;

        // Store connection settings in values
        $apiConfig->setValue('timeout_ms', (int) $request->input('rest_timeout_ms', 5000));
        $apiConfig->setValue('proxy', (string) $request->input('rest_proxy', ''));

        // Save auth values dynamically from schema fields
        \Illuminate\Support\Facades\Log::debug('Processing auth fields', [
            'device_id' => $device->device_id,
            'schema_key' => $schema->key,
            'schema_fields_count' => $schema->fields->count(),
            'all_request_keys' => array_keys($request->all()),
            'username_in_request' => $request->has('username'),
            'password_in_request' => $request->has('password'),
            'username_raw' => $request->input('username'),
            'password_length' => strlen((string) $request->input('password')),
        ]);

        foreach ($schema->fields as $field) {
            $fieldName = $field->name;
            $inputValue = $request->input($fieldName);
            $hasFilled = $request->filled($fieldName);
            $hasInput = $request->has($fieldName);

            \Illuminate\Support\Facades\Log::debug('Processing field', [
                'field_name' => $fieldName,
                'field_type' => $field->type,
                'has_input' => $hasInput,
                'filled' => $hasFilled,
                'value_length' => is_string($inputValue) ? strlen($inputValue) : 0,
            ]);

            if ($field->type === 'password') {
                if ($request->filled($fieldName)) {
                    $apiConfig->setValue($fieldName, $inputValue);
                    \Illuminate\Support\Facades\Log::debug('Set password field', ['field' => $fieldName]);
                } elseif ($schemaChanged) {
                    $apiConfig->setValue($fieldName, null);
                    \Illuminate\Support\Facades\Log::debug('Cleared password field due to schema change', ['field' => $fieldName]);
                } else {
                    \Illuminate\Support\Facades\Log::debug('Skipped password field (empty and no schema change)', ['field' => $fieldName]);
                }
            } else {
                // Use default value if input is empty
                if ($inputValue === null || $inputValue === '') {
                    $inputValue = $field->default;
                }
                $apiConfig->setValue($fieldName, $inputValue);
                \Illuminate\Support\Facades\Log::debug('Set non-password field', ['field' => $fieldName, 'value' => $inputValue]);
            }
        }

        $apiConfig->save();

        // Save custom endpoints if provided
        // Only save endpoints if none exist yet (initial setup) or if explicitly requested
        // Individual endpoint updates are handled via the updateEndpoint() AJAX method
        $existingEndpointsCount = \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)->count();

        $endpointsJson = $request->input('rest_endpoints');
        if ($endpointsJson && $endpointsJson !== '[]' && $endpointsJson !== '') {
            try {
                $endpoints = json_decode($endpointsJson, true);
                if (is_array($endpoints)) {
                    // Only delete/recreate if no endpoints exist yet (initial setup from template)
                    // Or if the user explicitly removed all endpoints (would trigger the else clause below)
                    if ($existingEndpointsCount === 0 || count($endpoints) === 0) {
                        // Delete existing endpoints for this device
                        \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)->delete();

                        // Create new endpoint records
                        foreach ($endpoints as $index => $ep) {
                            \App\Models\DeviceApiEndpoint::create([
                                'device_id' => $device->device_id,
                                'name' => $ep['name'] ?? null,
                                'path' => $ep['path'] ?? '',
                                'method' => $ep['method'] ?? 'GET',
                                'capability' => $ep['category'] ?? 'general',
                                'poll_interval' => $ep['poll_interval'] ?? 300,
                                'enabled' => $ep['enabled'] ?? true,
                                'transform' => $ep['transform'] ?? null,
                                'headers' => $ep['headers'] ?? null,
                                'request_body' => $ep['request_body'] ?? null,
                                'display_order' => $index,
                            ]);
                        }
                        \Illuminate\Support\Facades\Log::info("Saved {count} endpoints for device {$device->device_id}", ['count' => count($endpoints)]);

                        // Also keep in attribs for backward compatibility during migration
                        $device->setAttrib('rest_endpoints', $endpointsJson);
                    } else {
                        \Illuminate\Support\Facades\Log::info("Skipped endpoint save for device {$device->device_id} - endpoints already exist and are managed via AJAX");
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to save rest_endpoints: {$e->getMessage()}");
                toast()->error(__('Failed to save endpoints: ' . $e->getMessage()));
            }
        } elseif ($existingEndpointsCount > 0 && (!$endpointsJson || $endpointsJson === '[]')) {
            // Only clear endpoints if they exist and the hidden field is explicitly empty
            // This prevents accidental deletion
            \Illuminate\Support\Facades\Log::warning("Not clearing endpoints for device {$device->device_id} - use the delete button in the UI instead");
        }

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

		        // Load schema and template models
		        $schemaModel = \App\Models\DeviceApiAuthSchema::with('fields')->where('key', $authType)->first();
		        if (!$schemaModel) {
		            return response()->json([
		                'ok' => false,
		                'error' => 'Authentication schema not found',
		            ], 404);
		        }

		        $templateModel = !empty($templateKey)
		            ? \App\Models\DeviceApiTemplate::where('key', $templateKey)->first()
		            : null;

		        // Parse extra headers
		        $headersString = $request->input('rest_headers', '');
		        $extraHeaders = [];
		        if (!empty($headersString)) {
		            foreach (explode("\n", $headersString) as $line) {
		                $line = trim($line);
		                if (empty($line)) continue;
		                $parts = explode(':', $line, 2);
		                if (count($parts) === 2) {
		                    $extraHeaders[trim($parts[0])] = trim($parts[1]);
		                }
		            }
		        }

		        // Variables to capture test results
		        $latencyMs = 0;
		        $apiInfo = null;

		        // Use database transaction to test without persisting changes
		        try {
		            DB::transaction(function () use ($device, $baseUrl, $schemaModel, $templateModel, $extraHeaders, $request, &$latencyMs, &$apiInfo) {
		                // Get existing config or create new one
		                $existingConfig = DeviceApiConfig::with('schema.fields')->where('device_id', $device->device_id)->first();

		                // Create or update config for testing
		                $testConfig = $existingConfig ?? new DeviceApiConfig();
		                $testConfig->device_id = $device->device_id;
		                $testConfig->schema_id = $schemaModel->id;
		                $testConfig->template_id = $templateModel?->id;
		                $testConfig->base_url = rtrim($baseUrl, '/');
		                $testConfig->verify_ssl = $request->boolean('rest_verify_tls', true);
		                $testConfig->extra_headers = $extraHeaders;

		                // Set connection settings
		                $testConfig->setValue('timeout_ms', (int) $request->input('rest_timeout_ms', 5000));
		                $testConfig->setValue('proxy', (string) $request->input('rest_proxy', ''));

		                // Set auth field values
		                foreach ($schemaModel->fields as $field) {
		                    $value = $request->input($field->name);

		                    // For password fields, use existing value if not provided
		                    if ($field->type === 'password' && ($value === null || $value === '')) {
		                        if ($existingConfig && $existingConfig->schema_id === $schemaModel->id) {
		                            $value = $existingConfig->getValue($field->name);
		                        }
		                    }

		                    // Use default value if empty and not a password field
		                    if (($value === null || $value === '') && $field->type !== 'password' && $field->default) {
		                        $value = $field->default;
		                    }

		                    if ($value !== null && $value !== '') {
		                        $testConfig->setValue($field->name, $value);
		                    }
		                }

		                // Save the config temporarily (will be rolled back after test)
		                $testConfig->save();

		                // Reload device with fresh config
		                $device->load(['apiConfig.template', 'apiConfig.schema']);

		                // Use the same client factory as discovery/polling
		                $start = microtime(true);
		                $client = \App\ApiClients\DeviceApiClientFactory::make($device);

		                if (!$client) {
		                    throw new \RuntimeException('Could not create API client for device. Check template and authentication settings.');
		                }

		                // Test connection using the client's isReachable method
		                if (!$client->isReachable()) {
		                    throw new \RuntimeException('Device is not reachable via API');
		                }

		                $latencyMs = (int) ((microtime(true) - $start) * 1000);

		                // Try to get API info for additional verification
		                try {
		                    $apiInfo = $client->getApiInfo();
		                } catch (\Throwable $e) {
		                    // API info is optional, ignore errors
		                }

		                // Rollback transaction - we don't want to save the test config yet
		                // User must click "Save Settings" to persist changes
		                throw new \Exception('ROLLBACK_TEST'); // This will trigger rollback
		            });
		        } catch (\Throwable $e) {
		            // Check if this was our intentional rollback
		            if ($e->getMessage() === 'ROLLBACK_TEST') {
		                // Test succeeded, return success (transaction was rolled back)
		                return response()->json([
		                    'ok' => true,
		                    'message' => 'Connection successful - click "Save Settings" to persist changes',
		                    'latency_ms' => $latencyMs,
		                    'api_info' => $apiInfo,
		                ]);
		            }

		            // Actual error occurred during test
		            throw $e;
		        }

		    } catch (\Throwable $e) {
		        // Actual error occurred
		        return response()->json([
		            'ok' => false,
		            'error' => $e->getMessage(),
		            'details' => config('app.debug') ? $e->getTraceAsString() : null,
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

    public function toggleEndpoint(Request $request, Device $device): JsonResponse
    {
        try {
            $endpointId = $request->input('endpoint_id');
            $enabled = $request->boolean('enabled');

            // If endpoint_id is provided, update that specific record
            if ($endpointId) {
                $endpoint = \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)
                    ->where('id', $endpointId)
                    ->first();

                if (!$endpoint) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Endpoint not found',
                    ], 404);
                }

                $endpoint->enabled = $enabled;
                $endpoint->save();

                \Illuminate\Support\Facades\Log::info("Toggled endpoint {$endpoint->id} ({$endpoint->path}) to " . ($enabled ? 'enabled' : 'disabled') . " for device {$device->device_id}");

                return response()->json([
                    'success' => true,
                    'message' => 'Endpoint ' . ($enabled ? 'enabled' : 'disabled'),
                    'endpoint' => [
                        'id' => $endpoint->id,
                        'path' => $endpoint->path,
                        'enabled' => $endpoint->enabled,
                    ],
                ]);
            }

            // Fallback: handle by index (for template endpoints that haven't been saved yet)
            $index = $request->input('index');
            $allEndpointsJson = $request->input('all_endpoints');

            if (!isset($index) || !$allEndpointsJson) {
                return response()->json([
                    'success' => false,
                    'error' => 'Either endpoint_id or index+all_endpoints required',
                ], 400);
            }

            $endpoints = json_decode($allEndpointsJson, true);
            if (!is_array($endpoints) || $index < 0 || $index >= count($endpoints)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid endpoint data',
                ], 400);
            }

            // Initialize endpoints from template and save them all
            \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)->delete();

            foreach ($endpoints as $idx => $ep) {
                $ep['enabled'] = ($idx === $index) ? $enabled : ($ep['enabled'] ?? true);

                \App\Models\DeviceApiEndpoint::create([
                    'device_id' => $device->device_id,
                    'name' => $ep['name'] ?? null,
                    'path' => $ep['path'] ?? '',
                    'method' => $ep['method'] ?? 'GET',
                    'capability' => $ep['category'] ?? 'general',
                    'poll_interval' => $ep['poll_interval'] ?? 300,
                    'enabled' => $ep['enabled'],
                    'transform' => $ep['transform'] ?? null,
                    'headers' => $ep['headers'] ?? null,
                    'request_body' => $ep['request_body'] ?? null,
                    'display_order' => $idx,
                ]);
            }

            \Illuminate\Support\Facades\Log::info("Initialized " . count($endpoints) . " endpoints and toggled index {$index} to " . ($enabled ? 'enabled' : 'disabled') . " for device {$device->device_id}");

            return response()->json([
                'success' => true,
                'message' => 'Endpoint ' . ($enabled ? 'enabled' : 'disabled'),
                'initialized' => true,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to toggle endpoint for device {$device->device_id}: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateEndpoint(Request $request, Device $device): JsonResponse
    {
        try {
            $endpointId = $request->input('endpoint_id');
            $changes = $request->input('changes', []);

            if (!$endpointId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint ID is required',
                ], 400);
            }

            if (empty($changes) || !is_array($changes)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No changes provided',
                ], 400);
            }

            // Find the endpoint
            $endpoint = \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)
                ->where('id', $endpointId)
                ->first();

            if (!$endpoint) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint not found',
                ], 404);
            }

            // Map frontend field names to database column names
            $fieldMapping = [
                'name' => 'name',
                'path' => 'path',
                'method' => 'method',
                'category' => 'capability',
                'poll_interval' => 'poll_interval',
                'description' => 'description',
                'enabled' => 'enabled',
                'transform' => 'transform',
                'headers' => 'headers',
                'request_body' => 'request_body',
            ];

            // Apply changes
            $updatedFields = [];
            foreach ($changes as $field => $value) {
                if (isset($fieldMapping[$field])) {
                    $dbField = $fieldMapping[$field];
                    $endpoint->$dbField = $value;
                    $updatedFields[] = $field;
                }
            }

            $endpoint->save();

            \Illuminate\Support\Facades\Log::info("Updated endpoint {$endpoint->id} ({$endpoint->path}) for device {$device->device_id}", [
                'updated_fields' => $updatedFields,
                'changes' => $changes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Endpoint updated successfully',
                'endpoint' => [
                    'id' => $endpoint->id,
                    'path' => $endpoint->path,
                    'updated_fields' => $updatedFields,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to update endpoint for device {$device->device_id}: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
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