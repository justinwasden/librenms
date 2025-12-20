<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - GuestDiscovery Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class GuestDiscovery extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$discovered = [];
        $sensors = [];
        $data = $payload['data'] ?? $payload;

        if (!is_array($data)) {
            return ['sensors' => $sensors];
        }

        // Count VMs and containers by status
        $vmCounts = ['total' => 0, 'running' => 0, 'stopped' => 0];
        $ctCounts = ['total' => 0, 'running' => 0, 'stopped' => 0];

        foreach ($data as $guest) {
            $type = $guest['type'] ?? '';
            $vmid = $guest['vmid'] ?? $guest['id'] ?? null;
            $name = $guest['name'] ?? "guest-$vmid";
            $status = strtolower($guest['status'] ?? 'unknown');
            $node = $guest['node'] ?? 'unknown';

            if ($vmid === null) {
                continue;
            }

            // Categorize by type
            if ($type === 'qemu') {
                $vmCounts['total']++;
                if ($status === 'running') {
                    $vmCounts['running']++;
                } else {
                    $vmCounts['stopped']++;
                }
            } elseif ($type === 'lxc') {
                $ctCounts['total']++;
                if ($status === 'running') {
                    $ctCounts['running']++;
                } else {
                    $ctCounts['stopped']++;
                }
            }

            // Store discovery info (could be used for auto-adding guest devices in the future)
            $discovered[] = [
                'vmid' => $vmid,
                'name' => $name,
                'type' => $type,
                'status' => $status,
                'node' => $node,
                'cpu' => $guest['cpu'] ?? null,
                'maxcpu' => $guest['maxcpu'] ?? null,
                'mem' => $guest['mem'] ?? null,
                'maxmem' => $guest['maxmem'] ?? null,
                'disk' => $guest['disk'] ?? null,
                'maxdisk' => $guest['maxdisk'] ?? null,
                'uptime' => $guest['uptime'] ?? null,
            ];
        }

        // Create count sensors
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox-guests',
            'sensor_descr' => 'Total VMs',
            'sensor_index' => 'guest_vm_total',
            'sensor_current' => $vmCounts['total'],
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox-guests',
            'sensor_descr' => 'Running VMs',
            'sensor_index' => 'guest_vm_running',
            'sensor_current' => $vmCounts['running'],
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox-guests',
            'sensor_descr' => 'Total Containers',
            'sensor_index' => 'guest_ct_total',
            'sensor_current' => $ctCounts['total'],
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox-guests',
            'sensor_descr' => 'Running Containers',
            'sensor_index' => 'guest_ct_running',
            'sensor_current' => $ctCounts['running'],
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        // Log discovered guests for potential future use
        \Illuminate\Support\Facades\Log::debug('Proxmox Guest Discovery', [
            'total_guests' => count($discovered),
            'vms' => $vmCounts,
            'containers' => $ctCounts,
        ]);

        // Convert discovered guests to vminfo format
        $vminfo = [];
        foreach ($discovered as $guest) {
            // Map Proxmox status to LibreNMS PowerState integer values
            // PowerState: OFF = 0, ON = 1, SUSPENDED = 2, UNKNOWN = 3
            $stateMap = [
                'running' => 1,  // PowerState::ON
                'stopped' => 0,  // PowerState::OFF
                'paused' => 2,   // PowerState::SUSPENDED
            ];
            $state = $stateMap[$guest['status']] ?? 3; // PowerState::UNKNOWN

            $vminfo[] = [
                'vm_type' => 'proxmox',
                'vmwVmVMID' => (string) $guest['vmid'],
                'vmwVmDisplayName' => $guest['name'],
                'vmwVmGuestOS' => $guest['type'] === 'lxc' ? 'Linux Container' : 'Unknown',
                'vmwVmMemSize' => isset($guest['maxmem']) ? (int) ($guest['maxmem'] / 1048576) : 0, // Convert to MB
                'vmwVmCpus' => $guest['maxcpu'] ?? 0,
                'vmwVmState' => $state,
                'vmwVmHostId' => $guest['node'] ?? null,
            ];
        }

        return ['sensors' => $sensors, 'vminfo' => $vminfo];
    }
}
