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

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\PollerGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Exceptions\HostRenameException;
use LibreNMS\Util\File;
use LibreNMS\Util\Number;

class EditDeviceController
{
    public function index(Device $device): View
    {
        $section = request()->get('section', 'device');

        // Handle API section
        if ($section === 'api') {
            return view('device.edit', [
                'device' => $device,
                'section' => 'api',
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
    }
}
