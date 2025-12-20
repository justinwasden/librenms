<?php

namespace LibreNMS\Util\Normalizers\Juniper;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Juniper - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: junos
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'junos';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['interface-information']['physical-interface'] ?? $payload['interfaces'] ?? [];

        if (!is_array($interfaces)) {
            return [];
        }

        // Handle both single and multiple interfaces
        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $status = strtolower($iface['admin-status'] ?? $iface['oper-status'] ?? 'unknown');

            $ports[] = [
                'ifIndex' => $iface['snmp-index'] ?? $idx,
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => $iface['if-type'] ?? 'ethernetCsmacd',
                'ifOperStatus' => ($status === 'up') ? 'up' : 'down',
                'ifAdminStatus' => ($status === 'up') ? 'up' : 'down',
                'ifSpeed' => $iface['speed'] ?? 0,
                'ifMtu' => $iface['mtu'] ?? 1514,
                'ifPhysAddress' => $iface['hardware-physical-address'] ?? $iface['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
