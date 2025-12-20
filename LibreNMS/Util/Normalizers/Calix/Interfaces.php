<?php

namespace LibreNMS\Util\Normalizers\Calix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Calix - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: calix
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'calix';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['description'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['operStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['adminStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['macAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
