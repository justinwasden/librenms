<?php

namespace LibreNMS\Util\Normalizers\Extreme;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Extreme - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: extreme
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'extreme';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['openconfig-interfaces:interfaces']['interface'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $state = $iface['state'] ?? [];

            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $state['description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($state['oper-status'] ?? 'DOWN') === 'UP' ? 'up' : 'down',
                'ifAdminStatus' => ($state['admin-status'] ?? 'DOWN') === 'UP' ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => $state['mtu'] ?? 1500,
                'ifPhysAddress' => $state['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
