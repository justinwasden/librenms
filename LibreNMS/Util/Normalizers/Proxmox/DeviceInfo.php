<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: proxmox
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $data = $payload['data'] ?? $payload;

        if (empty($data)) {
            return $deviceInfo;
        }

        // Hardware/Model - Use CPU model if available, otherwise use machine architecture
        if (isset($data['cpuinfo']['model'])) {
            $deviceInfo['hardware'] = $data['cpuinfo']['model'];
        } elseif (isset($data['current-kernel']['machine'])) {
            $sysname = $data['current-kernel']['sysname'] ?? 'Generic';
            $machine = $data['current-kernel']['machine'];
            $deviceInfo['hardware'] = "{$sysname} {$machine}";
        } elseif (isset($data['node'])) {
            $deviceInfo['hardware'] = 'Generic x86 64-bit';
        }

        // Version - Use kernel release version
        if (isset($data['current-kernel']['release'])) {
            $deviceInfo['version'] = $data['current-kernel']['release'];
        } elseif (isset($data['kversion'])) {
            // Extract version from kversion string (e.g., "Linux 6.14.11-4-pve #1...")
            if (preg_match('/Linux\s+([\d\.-]+)/', $data['kversion'], $matches)) {
                $deviceInfo['version'] = $matches[1];
            }
        }

        // Features - Store PVE version info
        if (isset($data['pveversion'])) {
            $deviceInfo['features'] = $data['pveversion'];
        }

        // Serial number (usually not available for virtual nodes)
        if (isset($data['serial'])) {
            $deviceInfo['serial'] = $data['serial'];
        }

        // System Object ID (Proxmox uses .1.3.6.1.4.1.2606)
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.2606';

        // Uptime (Proxmox provides uptime in seconds)
        if (isset($data['uptime'])) {
            $deviceInfo['uptime'] = (int) $data['uptime'];
        }

        // System Contact (if available)
        if (isset($data['contact'])) {
            $deviceInfo['sysContact'] = $data['contact'];
        }

        // Location (if available)
        if (isset($data['location'])) {
            $deviceInfo['location'] = $data['location'];
        }

        // System Name - Use the node name from Proxmox
        if (isset($data['node'])) {
            $deviceInfo['sysName'] = $data['node'];
        }

        return $deviceInfo;
    }
}
