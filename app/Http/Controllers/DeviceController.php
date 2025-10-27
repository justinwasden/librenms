<?php

namespace App\Http\Controllers;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\View\Components\Device\PageTabs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Util\Debug;
use LibreNMS\Util\Url;

class DeviceController extends Controller
{
    public function index(Request $request, $device, $current_tab = 'overview', $vars = '')
    {
        $device = str_replace('device=', '', $device);
        $device = is_numeric($device) ? DeviceCache::get((int) $device) : DeviceCache::getByHostname($device);
        $device_id = $device->device_id;

        if (! $device->exists) {
            abort(404);
        }

        DeviceCache::setPrimary($device_id);

        $current_tab = str_replace('tab=', '', $current_tab) ?: 'overview';

        if ($current_tab == 'port') {
            $vars = Url::parseLegacyPath($request->path());
            $port = $device->ports()->findOrFail($vars->get('port'));
            Gate::authorize('view', $port);
        } else {
            Gate::authorize('view', $device);
        }

        $tab_controller = PageTabs::getTab($current_tab);
        $title = $tab_controller->name();
        $data = $tab_controller->data($device, $request);

        $data_array = [
            'title' => $title,
            'device' => $device,
            'device_id' => $device_id,
            'data' => $data,
            'vars' => $vars,
            'current_tab' => $current_tab,
            'request' => $request,
        ];

        if (view()->exists('device.tabs.' . $current_tab)) {
            return view('device.tabs.' . $current_tab, $data_array);
        }

        $data_array['tab_content'] = $this->renderLegacyTab($current_tab, $device, $data);

        return view('device.tabs.legacy', $data_array);
    }

    private function renderLegacyTab($tab, Device $device, $data)
    {
        ob_start();
        $device = $device->toArray();
        $device['os_group'] = LibrenmsConfig::get("os.{$device['os']}.group");
        Debug::set(false);
        chdir(base_path());
        $init_modules = ['web', 'auth'];
        require base_path('/includes/init.php');

        $vars['device'] = $device['device_id'];
        $vars['tab'] = $tab;

        extract($data); // set preloaded data into variables
        include "includes/html/pages/device/$tab.inc.php";
        $output = ob_get_clean();
        ob_end_clean();

        return $output;
    }

    public function rediscover(Device $device): JsonResponse
    {
        $device->last_discovered = null;
        $saved = $device->save();

        return response()->json([
            'message' => $saved ? 'Device scheduled for discovery' : 'Failed to schedule device for discovery',
            'status' => $saved ? 'ok' : 'error',
        ]);
    }

    /**
     * Edit device settings. Renders Blade only for the Device API tab (section=api),
     * otherwise redirects to legacy edit page and auto-resolves the correct legacy
     * "Device Settings" section filename.
     */
//    public function edit(Device $device)
//    {
//        if (! auth()->user()->hasGlobalAdmin()) {
//            abort(403, 'Insufficient Privileges');
//        }
//
//        $section = request()->get('section');
//
//        // Render Blade for Device API only
//        if ($section === 'api') {
//            // Load templates filtered by device OS
//            $templates = \LibreNMS\Util\ApiTemplateManager::getTemplatesForOs($device->os);
//            $authTypes = \LibreNMS\Util\ApiTemplateManager::getAuthTypes();
//
//            // Load API config from database
//            $apiConfig = \App\Models\DeviceApiConfig::with(['schema.fields', 'template'])
//                ->where('device_id', $device->device_id)
//                ->first();
//
//            // If a template is selected, load it; otherwise auto-select if only one template matches
//            $selectedTemplate = $apiConfig?->template?->key ?? null;
//            if (!$selectedTemplate && count($templates) === 1) {
//                $selectedTemplate = array_key_first($templates);
//            }
//            $templateData = $selectedTemplate ? \LibreNMS\Util\ApiTemplateManager::loadTemplate($selectedTemplate) : null;
//
//            return view('device.edit', [
//                'device' => $device,
//                'section' => 'api',
//                'templates' => $templates,
//                'authTypes' => $authTypes,
//                'apiConfig' => $apiConfig,
//                'selectedTemplate' => $selectedTemplate,
//                'templateData' => $templateData,
//                'autoSelectTemplate' => !$apiConfig && count($templates) === 1,
//            ]);
//        }
//
//        // For Device Settings or other legacy sections, redirect to legacy UI
//        if ($section === null || $section === 'device') {
//            $section = $this->resolveLegacyDeviceSettingsSection();
//        }
//
//        return redirect(url("device/device={$device->device_id}/tab=edit/section={$section}"));
//    }
//
    /**
     * Update device API configuration and return to the legacy device page.
     * Saves sensitive fields encrypted.
     */
//    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
//    {
//        if (! auth()->user()->hasGlobalAdmin()) {
//            abort(403, 'Insufficient Privileges');
//        }
//
//        // Optional: basic device fields
//        if ($request->filled('hostname')) {
//            $device->hostname = $request->input('hostname');
//        }
//        if ($request->filled('display')) {
//            $device->display = $request->input('display');
//        }
//        if ($request->filled('overwrite_ip')) {
//            $device->overwrite_ip = $request->input('overwrite_ip');
//        }
//
//        // Device API configuration - save to database
//        if ($request->has('rest_enabled')) {
//            // Check if API is being disabled
//            if (!$request->boolean('rest_enabled')) {
//                // Delete the config if it exists
//                \App\Models\DeviceApiConfig::where('device_id', $device->device_id)->delete();
//            } else {
//                // Get template and schema IDs
//                $templateKey = $request->input('rest_template');
//                $authTypeKey = $request->input('rest_auth_type');
//
//                if ($templateKey && $authTypeKey) {
//                    $template = \App\Models\DeviceApiTemplate::where('key', $templateKey)->first();
//                    $schema = \App\Models\DeviceApiAuthSchema::where('key', $authTypeKey)->first();
//
//                    if ($template && $schema) {
//                        // Find or create the config
//                        $apiConfig = \App\Models\DeviceApiConfig::firstOrNew([
//                            'device_id' => $device->device_id,
//                        ]);
//
//                        // Update config fields
//                        $apiConfig->template_id = $template->id;
//                        $apiConfig->schema_id = $schema->id;
//                        $apiConfig->base_url = $request->input('rest_base_url') ?? '';
//                        $apiConfig->verify_ssl = $request->boolean('rest_verify_tls', true);
//
//                        // Parse extra headers
//                        $headersString = $request->input('rest_headers', '');
//                        $extraHeaders = [];
//                        if (!empty($headersString)) {
//                            foreach (explode("\n", $headersString) as $line) {
//                                $line = trim($line);
//                                if (empty($line)) {
//                                    continue;
//                                }
//                                $parts = explode(':', $line, 2);
//                                if (count($parts) === 2) {
//                                    $extraHeaders[trim($parts[0])] = trim($parts[1]);
//                                }
//                            }
//                        }
//                        $apiConfig->extra_headers = $extraHeaders;
//
//                        // Save auth values - dynamically handle all schema fields
//                        foreach ($schema->fields as $field) {
//                            $fieldName = $field->name;
//                            if ($request->filled($fieldName)) {
//                                $apiConfig->setValue($fieldName, $request->input($fieldName));
//                            }
//                        }
//
//                        $apiConfig->save();
//                    }
//                }
//            }
//        }
//
//        $device->save();
//
//        // Return to the API settings page if API was being configured, otherwise legacy device page
//        if ($request->has('rest_enabled')) {
//            return redirect()->route('device.edit', ['device' => $device->device_id, 'section' => 'api'])
//                ->with('status', 'Device API settings updated successfully');
//        }
//
//        // Return to the legacy device page
//        return redirect(url("device/{$device->device_id}"))->with('status', 'Device updated successfully');
//    }

    /**
     * Detect the actual legacy include section for "Device Settings"
     * (filename without .inc.php) under includes/html/pages/device/edit/.
     */
//    private function resolveLegacyDeviceSettingsSection(): string
//    {
//        $base = base_path('includes/html/pages/device/edit/');
//        $candidates = [
//            'device.inc.php',
//            'general.inc.php',
//            'settings.inc.php',
//        ];
//
//        foreach ($candidates as $file) {
//            if (is_file($base . $file)) {
//                return basename($file, '.inc.php');
//            }
//        }
//
//        // Fallback to 'device'
//        return 'device';
//    }
}