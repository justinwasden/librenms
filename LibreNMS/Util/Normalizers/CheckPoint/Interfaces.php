<?php

namespace LibreNMS\Util\Normalizers\CheckPoint;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * CheckPoint - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: checkpoint
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'checkpoint';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['objects'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['comments'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
