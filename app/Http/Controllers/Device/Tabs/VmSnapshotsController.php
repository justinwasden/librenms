<?php

namespace App\Http\Controllers\Device\Tabs;

use App\Models\Device;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab;

class VmSnapshotsController implements DeviceTab
{
    public function visible(Device $device): bool
    {
        // Show VM snapshots tab for VMware vCenter devices
        return in_array($device->os, ['vmware-vcsa', 'vmware'], true)
            && \DB::table('vmware_vm_snapshots')
                ->where('device_id', $device->device_id)
                ->exists();
    }

    public function slug(): string
    {
        return 'vm-snapshots';
    }

    public function icon(): string
    {
        return 'fa-camera';
    }

    public function name(): string
    {
        return 'VM Snapshots';
    }

    public function data(Device $device, Request $request): array
    {
        $snapshots = \DB::table('vmware_vm_snapshots')
            ->where('device_id', $device->device_id)
            ->orderBy('snapshot_count', 'desc')
            ->orderBy('vm_name')
            ->get();

        return [
            'device' => $device,
            'snapshots' => $snapshots,
        ];
    }
}
