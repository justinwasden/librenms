<?php

namespace LibreNMS\Util\Normalizers\PaloAlto;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * PaloAlto - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: pan
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'pan';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['result']['ifnet']['entry'] ?? [];

        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['alias'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
