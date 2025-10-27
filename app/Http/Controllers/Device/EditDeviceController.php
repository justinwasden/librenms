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
use App\Models\DeviceApiConfig;
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
use App\Http\Controllers\DeviceController;
use App\Models\DeviceApiAuthSchema;
use App\Models\DeviceApiTemplate;

class EditDeviceController
{
    public function index(Device $device): View
    {
        // Eager load attribs to ensure they're available in the view
        $device->load('attribs');

        $section = request()->get('section', 'device');

        // Handle API section (Renders the blade partial)
        if ($section === 'api') {
            // ... (Keep existing API loading logic)
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
            // ... (Keep existing Device Settings loading logic)
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
            // Clear headers and TLS verify flags too
            $device->forgetAttrib('rest_headers');
            $device->forgetAttrib('rest_verify_tls');

            return;
        }

        // Get template and schema IDs
        $templateKey = $request->input('rest_template');
        $authTypeKey = $request->input('rest_auth_type');

        if (!$templateKey || !$authTypeKey) {
            return;
        }

        $template = \App\Models\DeviceApiTemplate::where('key', $templateKey)->first();
        $schema = \App\Models\DeviceApiAuthSchema::where('key', $authTypeKey)->first();

        if (!$template || !$schema) {
            return;
        }

        // Persist selected template and auth type to device attribs
        $device->setAttrib('rest_template_key', $template->key);
        $device->setAttrib('rest_auth_type', $schema->key);
        $device->setAttrib('rest_enabled', 1);

        // Base URL override from form or resolve from template pattern
        $overrideBase = $request->input('rest_base_url');
        if (!empty($overrideBase)) {
            $device->setAttrib('rest_base_url', rtrim((string) $overrideBase, '/'));
        } else {
            // Resolve and persist base_url from template's base_url_pattern
            \LibreNMS\Util\DeviceApiSettings::ensureResolvedBaseUrl($device); // << DeviceApiSettings ensures base_url resolution <sup><a href="LibreNMS\Util\DeviceApiSettings.php" class="markdown-link" target="_blank">1</a></sup>
        }

        // Persist TLS verification flag in device attribs for httpOptions()
        $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0); // << used by httpOptions() <sup><a href="LibreNMS\Util\DeviceApiSettings.php" class="markdown-link" target="_blank">1</a></sup>

        // Find or create the config
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

        // Parse extra headers (one per line "Header: value")
        $headersString = $request->input('rest_headers', '');
        $extraHeaders = [];
        if (!empty($headersString)) {
            foreach (explode("\n", $headersString) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $extraHeaders[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        $apiConfig->extra_headers = $extraHeaders;

        // ALSO persist headers to device attribs as JSON for DeviceApiSettings::httpOptions()
        $device->setAttrib('rest_headers', json_encode($extraHeaders)); // << httpOptions() reads rest_headers JSON <sup><a href="LibreNMS\Util\DeviceApiSettings.php" class="markdown-link" target="_blank">1</a></sup>

        // Save auth values dynamically from schema fields
        $values = [];
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
    }

    public function testConnection(Device $device)
    {
        $tplKey = request('template_key');
        $tpl = \LibreNMS\Util\ApiTemplateManager::loadTemplate($tplKey);
        if (!$tpl) return response()->json(['ok' => false, 'error' => 'Template not found'], 404);

        \LibreNMS\Util\DeviceApiSettings::ensureResolvedBaseUrl($device);

        try {
            $client = $this->makeClient($device, $tpl);
            // Pick a simple info endpoint by vendor
            $path = match ($tpl['vendor']) {
                'proxmox_ve_token', 'proxmox_ve_ticket' => '/cluster/status',
                'purestorage_flasharray' => '/arrays',
                'vmware_vcenter' => '/appliance/health/system', // or /vcenter/host
                default => '/',
            };
            $path = \LibreNMS\Util\EndpointPathResolver::resolve($device, $path);
            $data = $client->get($path);
            return response()->json(['ok' => true, 'sample' => array_slice($data, 0, 10)]);
        } catch (\Throwable $e) {
            // map common causes to friendly messages
            $msg = $e->getMessage();
            if (str_contains($msg, 'SSL')) $msg .= ' (Tip: check TLS verification settings)';
            return response()->json(['ok' => false, 'error' => $msg], 400);
        }
    }

    protected function makeClient(Device $device, array $tpl)
    {
        return match ($tpl['vendor']) {
            'proxmox_ve_token', 'proxmox_ve_ticket' => new \App\ApiClients\Proxmox\ProxmoxApiClient($device),
            'purestorage_flasharray' => new \App\ApiClients\PureStorage\FlashArrayClient($device, ['strategy_key' => $tpl['auth_type']]),
            'vmware_vcenter' => new \App\ApiClients\Vmware\VcenterClient($device),
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
        $templates = \LibreNMS\Util\ApiTemplateManager::getTemplatesForOs($os);

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
        $section = $request->get('section', 'device');

        // Handle the REST API settings tab
        if ($section === 'api') {
            $templates = ApiTemplateManager::getTemplatesForOs($device->os);
            $authTypes = ApiTemplateManager::getAuthSchemasForOs($device->os);

            $apiConfig = DeviceApiConfig::where('device_id', $device->device_id)->first();
            $selectedTemplate = $request->old('rest_template', $apiConfig?->template?->key ?? null);
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

        // Handle the primary 'device' settings tab (Blade-based partial)
        $types = collect(\App\Facades\LibrenmsConfig::get('device_types'))->keyBy('type');
        if (!$types->has($device->type)) {
            $types->put($device->type, [
                'icon' => null,
                'text' => ucfirst($device->type),
                'type' => $device->type,
            ]);
        }

        [$rrd_size, $rrd_num] = \LibreNMS\Data\Store\Rrd::dirFromHost($device->hostname)
            ? \LibreNMS\Util\File::getFolderSize(\LibreNMS\Data\Store\Rrd::dirFromHost($device->hostname))
            : [0, 0];

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
            'default_type' => \App\Facades\LibrenmsConfig::getOsSetting($device->os, 'type'),
            'parents' => $device->parents()->pluck('hostname', 'device_id'),
            'poller_groups' => PollerGroup::orderBy('group_name')->pluck('group_name', 'id'),
            'default_poller_group' => \App\Facades\LibrenmsConfig::get('distributed_poller_group'),
            'override_sysContact_bool' => $device->getAttrib('override_sysContact_bool'),
            'override_sysContact_string' => $device->getAttrib('override_sysContact_string'),
            'maintenance' => $isUnderMaintenance,
            'default_maintenance_behavior' => \LibreNMS\Enum\MaintenanceBehavior::from((int) \App\Facades\LibrenmsConfig::get('alert.scheduled_maintenance_default_behavior'))->value,
            'exclusive_maintenance_id' => $exclusive_schedule_id,
            'rrd_size' => Number::formatBi($rrd_size),
            'rrd_num' => $rrd_num,
        ]);
    }
}