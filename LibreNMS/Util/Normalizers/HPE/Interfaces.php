<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: nimble
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'nimble';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['data'] ?? $payload['network_interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['name'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['link_status'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => ($iface['link_speed'] ?? 0) * 1000000,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
