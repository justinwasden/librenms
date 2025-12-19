<?php

namespace App\Plugins\Hooks;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Device Overview hook to render VMware vCenter Cluster metrics.
 *
 * This hook provides data to the view resources/views/device/overview/vm-cluster.blade.php
 * and limits rendering to vCenter devices.
 */
class VcenterClustersHook extends DeviceOverviewHook
{
    /** @var string The Blade view to render */
    public string $view = 'device.overview.vm-cluster';

    /**
     * Only allow this panel for vCenter devices.
     *
     * Adjust the OS key if your environment uses a different identifier.
     */
    public function authorize(User $user, Device $device): bool
    {
        return $device->os === 'vmware-vcsa';
    }

    /**
     * Prepare data for the view.
     *
     * @return array<string, mixed>
     */
    public function data(Device $device): array
    {
        $clusters = DB::table('clusters')
            ->where('device_id', $device->device_id)
            ->where('provider_type', 'vmware-vcsa')
            ->orderBy('cluster_name')
            ->get();

        return [
            'device'   => $device,
            'clusters' => $clusters,
        ];
    }
}
