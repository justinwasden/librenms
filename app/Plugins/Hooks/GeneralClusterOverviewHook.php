<?php

namespace App\Plugins\Hooks;

use App\Models\Device;
use App\Models\User;
use App\Models\Cluster;
use Illuminate\Support\Facades\DB;

/**
 * Generic Device Overview hook to render clusters for known provider types.
 * View: resources/views/device/overview/cluster-general.blade.php
 */
class GeneralClusterOverviewHook extends DeviceOverviewHook
{
    public string $view = 'device.overview.cluster-general';

    /**
     * Only show for devices we recognize as clusters providers by OS.
     */
    public function authorize(User $user, Device $device): bool
    {
        // Adjust mappings to your environment's OS identifiers
        return in_array($device->os, [
            'vmware-vcsa',
            'hyperv',
            'proxmox',
            'purestorage',
            'rdp_gateway',
            'f5',
            'netscaler',
        ], true);
    }

    /**
     * Provide clusters for this device from unified schema.
     */
    public function data(Device $device): array
    {
        $clusters = Cluster::query()
            ->where('device_id', $device->device_id)
            ->orderBy('cluster_name')
            ->get();

        return [
            'device' => $device,
            'clusters' => $clusters,
        ];
    }
}
