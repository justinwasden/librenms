<?php

namespace LibreNMS\Util\Normalizers\SonicWall;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * SonicWall - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: sonic
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'sonic';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['comment'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['link'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['enable'] ?? false) ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
