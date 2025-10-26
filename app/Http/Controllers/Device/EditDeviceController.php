<?php

/**
 * EditDeviceController.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
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
use App\Models\DeviceGroup;
use App\Models\PollerGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Exceptions\HostRenameException;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;
use LibreNMS\Util\File;
use LibreNMS\Util\Number;

class EditDeviceController
{
    public function index(Device $device): View
    {
        // Eager load attribs to ensure they're available in the view
        $device->load('attribs');

        $section = request()->get('section', 'device');

        // Handle API section
        if ($section === 'api') {
            // Load templates filtered by device OS
            $templates = ApiTemplateManager::getTemplatesForOs($device->os);
            $authTypes = ApiTemplateManager::getAuthTypes();

            // Get currently configured endpoints (or empty array if none)
            $configuredEndpoints = json_decode($device->getAttrib('rest_endpoints', '[]'), true);

            // If a template is selected, load it; otherwise auto-select if only one template matches
            $selectedTemplate = $device->getAttrib('rest_template');
            if (!$selectedTemplate && count($templates) === 1) {
                $selectedTemplate = array_key_first($templates);
            }
            $templateData = $selectedTemplate ? ApiTemplateManager::loadTemplate($selectedTemplate) : null;

            return view('device.edit', [
                'device' => $device,
                'section' => 'api',
                'templates' => $templates,
                'authTypes' => $authTypes,
                'configuredEndpoints' => $configuredEndpoints,
                'selectedTemplate' => $selectedTemplate,
                'templateData' => $templateData,
                'autoSelectTemplate' => !$device->getAttrib('rest_template') && count($templates) === 1,
            ]);
        }

        // Handle device settings section (default)
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
        // Device API attributes
        $device->setAttrib('rest_enabled', $request->boolean('rest_enabled') ? 1 : 0);
        $device->setAttrib('rest_template', $request->input('rest_template') ?? '');
        $device->setAttrib('rest_vendor', $request->input('rest_vendor') ?? '');
        $device->setAttrib('rest_base_url', $request->input('rest_base_url') ?? '');
        $device->setAttrib('rest_auth_type', $request->input('rest_auth_type') ?? '');

        $device->setAttrib('rest_headers', $request->input('rest_headers') ?? '');
        $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0);
        $device->setAttrib('rest_timeout_ms', (int) ($request->input('rest_timeout_ms') ?? 5000));
        $device->setAttrib('rest_proxy', $request->input('rest_proxy') ?? '');
        $device->setAttrib('rest_rate_limit_qps', (int) ($request->input('rest_rate_limit_qps') ?? 10));

        // Save endpoints configuration
        if ($request->has('rest_endpoints')) {
            $endpoints = $request->input('rest_endpoints');
            $device->setAttrib('rest_endpoints', is_array($endpoints) ? json_encode($endpoints) : ($endpoints ?? '[]'));
        }

        if ($request->filled('rest_token')) {
            $device->setAttrib('rest_token_enc', Crypt::encryptString($request->input('rest_token')));
        }
        if ($request->filled('rest_username')) {
            $device->setAttrib('rest_username', $request->input('rest_username'));
        }
        if ($request->filled('rest_password')) {
            $device->setAttrib('rest_password_enc', Crypt::encryptString($request->input('rest_password')));
        }

        // Proxmox token
        if ($request->filled('proxmox_token_user')) {
            $device->setAttrib('proxmox_token_user', $request->input('proxmox_token_user'));
        }
        if ($request->filled('proxmox_token_id')) {
            $device->setAttrib('proxmox_token_id', $request->input('proxmox_token_id'));
        }
        if ($request->filled('proxmox_token')) {
            $device->setAttrib('proxmox_token_enc', Crypt::encryptString($request->input('proxmox_token')));
        }

        // Proxmox ticket
        if ($request->filled('proxmox_username')) {
            $device->setAttrib('proxmox_username', $request->input('proxmox_username'));
        }
        if ($request->filled('proxmox_password')) {
            $device->setAttrib('proxmox_password_enc', Crypt::encryptString($request->input('proxmox_password')));
        }

        $device->save();
    }

    /**
     * Test API connection with provided credentials
     */
    public function testApiConnection(Request $request, Device $device): JsonResponse
    {
        try {
            $baseUrl = $request->input('rest_base_url');

            // Validate base URL
            if (empty($baseUrl)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Base URL is required',
                ]);
            }

            // Build temporary options from request
            $options = [
                'base_url' => $baseUrl,
                'verify_tls' => $request->boolean('rest_verify_tls', true),
                'timeout_ms' => (int) $request->input('rest_timeout_ms', 5000),
                'headers' => [],
                'enable_circuit_breaker' => false, // Disable circuit breaker for testing
                'max_retries' => 0, // Don't retry during testing for faster feedback
            ];

            // Add auth headers based on type
            $authType = $request->input('rest_auth_type', 'bearer');
            $token = $request->input('rest_token');
            $username = $request->input('rest_username');
            $password = $request->input('rest_password');

            if ($authType === 'bearer' && $token) {
                $options['headers']['Authorization'] = 'Bearer ' . $token;
            } elseif ($authType === 'apikey' && $token) {
                $options['headers']['X-API-Key'] = $token;
            } elseif ($authType === 'basic' && $username) {
                $options['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . ($password ?? ''));
            }

            // Create client and test with better error handling
            $client = new DeviceHttpClient($options);

            // Try to make a simple request
            try {
                // Try root path first
                $data = $client->get('/');

                return response()->json([
                    'success' => true,
                    'vendor' => 'generic',
                    'version' => 'connected',
                    'base_url' => $baseUrl,
                    'message' => 'Connection successful',
                ]);
            } catch (\Throwable $e) {
                // If root fails, provide detailed error
                $errorMessage = $e->getMessage();

                // Extract useful error information
                if (str_contains($errorMessage, 'Could not resolve host')) {
                    $errorMessage = 'Could not resolve hostname - check the URL';
                } elseif (str_contains($errorMessage, 'Connection refused')) {
                    $errorMessage = 'Connection refused - check if the service is running';
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $errorMessage = 'Connection timed out - check firewall/network settings';
                } elseif (str_contains($errorMessage, 'SSL')) {
                    $errorMessage = 'SSL/TLS error - try disabling certificate verification for testing';
                } elseif (str_contains($errorMessage, '401')) {
                    $errorMessage = 'Authentication failed (HTTP 401) - check credentials';
                } elseif (str_contains($errorMessage, '403')) {
                    $errorMessage = 'Access forbidden (HTTP 403) - check permissions';
                } elseif (str_contains($errorMessage, '404')) {
                    $errorMessage = 'Endpoint not found (HTTP 404) - the base URL might need an API path like /api';
                } elseif (preg_match('/returned (\d+)/', $errorMessage, $matches)) {
                    $errorMessage = 'API returned HTTP ' . $matches[1] . ' - connection works but endpoint may need adjustment';
                }

                return response()->json([
                    'success' => false,
                    'error' => $errorMessage,
                    'raw_error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset circuit breaker for a device
     */
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
}
