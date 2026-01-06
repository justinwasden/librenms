<?php

namespace App\Plugins\Hooks;

use App\Models\Device;
use App\Models\HypervisorCluster;
use App\Models\HypervisorHost;
use App\Models\User;
use App\Models\Vminfo;

/**
 * Device Overview hook to render VMware vCenter Cluster metrics.
 *
 * This hook provides data to the view resources/views/device/overview/vcenter-topology.blade.php
 * and limits rendering to vCenter devices.
 */
class VcenterClustersHook extends DeviceOverviewHook
{
    /** @var string The Blade view to render */
    public string $view = 'device.overview.vcenter-topology';

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
        $clusterRows = HypervisorCluster::query()
            ->where('device_id', $device->device_id)
            ->where('cluster_type', 'vmware')
            ->orderBy('cluster_name')
            ->get();

        $datacenters = [];
        $clusters = [];
        foreach ($clusterRows as $cluster) {
            if ($cluster->cluster_level === 'datacenter') {
                $datacenters[] = [
                    'id' => $cluster->cluster_id,
                    'name' => $cluster->cluster_name,
                ];
            } elseif ($cluster->cluster_level === 'cluster') {
                $metadata = $cluster->metadata ?? [];
                $clusters[] = [
                    'id' => $cluster->cluster_id,
                    'name' => $cluster->cluster_name,
                    'parent_id' => $cluster->parent_id,
                    'parent_name' => $cluster->parent_name,
                    'drs_enabled' => (bool) ($metadata['drs_enabled'] ?? false),
                    'ha_enabled' => (bool) ($metadata['ha_enabled'] ?? false),
                    'vsan_enabled' => (bool) ($metadata['vsan_enabled'] ?? false),
                ];
            }
        }

        $hostRows = HypervisorHost::query()
            ->where('device_id', $device->device_id)
            ->where('host_type', 'esxi')
            ->orderBy('host_name')
            ->get();

        $hosts = [];
        $hostClusterMap = [];
        foreach ($hostRows as $host) {
            $hosts[] = [
                'id' => $host->host_id,
                'name' => $host->host_name,
                'status' => $host->status ?? 'unknown',
                'cluster_id' => $host->cluster_id,
            ];
            if ($host->host_id && $host->cluster_id) {
                $hostClusterMap[$host->host_id] = $host->cluster_id;
            }
        }

        $vminfos = Vminfo::query()
            ->where('device_id', $device->device_id)
            ->where('vm_type', 'vmware')
            ->orderBy('vmwVmDisplayName')
            ->get();

        $vms = [];
        foreach ($vminfos as $vm) {
            $powerState = match ((int) $vm->vmwVmState) {
                1 => 'POWERED_ON',
                0 => 'POWERED_OFF',
                2 => 'SUSPENDED',
                default => 'UNKNOWN',
            };

            $clusterId = null;
            if (!empty($vm->vmwVmHostId) && isset($hostClusterMap[$vm->vmwVmHostId])) {
                $clusterId = $hostClusterMap[$vm->vmwVmHostId];
            }

            $vms[] = [
                'id' => $vm->vmwVmVMID,
                'name' => $vm->vmwVmDisplayName,
                'power_state' => $powerState,
                'cpu_count' => $vm->vmwVmCpus,
                'memory_size_MiB' => $vm->vmwVmMemSize,
                'host_id' => $vm->vmwVmHostId,
                'cluster_id' => $clusterId,
            ];
        }

        return [
            'device'   => $device,
            'topology' => [
                'datacenters' => $datacenters,
                'clusters' => $clusters,
                'hosts' => $hosts,
                'vms' => $vms,
                'vsan_status' => [],
            ],
        ];
    }
}
