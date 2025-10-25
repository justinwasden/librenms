<?php

namespace App\Http\Controllers;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\View\Components\Device\PageTabs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Util\Debug;
use LibreNMS\Util\Url;

class DeviceController
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

    public function edit(Device $device)
    {
        if (! auth()->user()->hasGlobalAdmin()) {
            abort(403, 'Insufficient Privileges');
        }

        return view('device.edit', compact('device'));
    }

    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
    {
        if (! auth()->user()->hasGlobalAdmin()) {
            abort(403, 'Insufficient Privileges');
        }

        // Example: update hostname
        $device->hostname = $request->input('hostname', $device->hostname);

        // Device API attributes
        $device->setAttrib('rest_enabled', $request->boolean('rest_enabled') ? 1 : 0);
        $device->setAttrib('rest_vendor', $request->input('rest_vendor', ''));
        $device->setAttrib('rest_base_url', $request->input('rest_base_url', ''));
        $device->setAttrib('rest_auth_type', $request->input('rest_auth_type', ''));

        $device->setAttrib('rest_headers', $request->input('rest_headers', ''));
        $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0);
        $device->setAttrib('rest_timeout_ms', (int) $request->input('rest_timeout_ms', 5000));
        $device->setAttrib('rest_proxy', $request->input('rest_proxy', ''));

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

        return redirect()->route('device.show', ['device' => $device->device_id])
            ->with('status', 'Device updated successfully');
    }
}
