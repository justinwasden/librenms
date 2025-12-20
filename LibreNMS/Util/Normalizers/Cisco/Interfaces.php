<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: ndfc
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'ndfc';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['DATA'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['ifName'] ?? "interface-$idx",
                'ifDescr' => $iface['description'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['operSt'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['adminSt'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => null,
            ];
        }

        return ['ports' => $ports];
    }
}
