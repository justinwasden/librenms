<?php

namespace App\Http\Controllers\Device\Tabs;

use App\Models\Device;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab;

class ClustersController implements DeviceTab
{
    public function visible(Device $device): bool
    {
        // Show clusters tab for VMware vCenter devices that have cluster data
        return in_array($device->os, ['vmware-vcsa', 'vmware'], true)
            && \DB::table('hypervisor_clusters')
                ->where('device_id', $device->device_id)
                ->where('cluster_type', 'vmware')
                ->exists();
    }

    public function slug(): string
    {
        return 'clusters';
    }

    public function icon(): string
    {
        return 'fa-server';
    }

    public function name(): string
    {
        return 'Clusters';
    }

    public function data(Device $device, Request $request): array
    {
        return [
            'device' => $device,
        ];
    }
}
