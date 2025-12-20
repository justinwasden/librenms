<?php

namespace LibreNMS\Util\Normalizers\Arista;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Arista - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: arista
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'arista';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $name => $iface) {
            $ports[] = [
                'ifIndex' => count($ports),
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['lineProtocolStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['interfaceStatus'] ?? 'disabled') === 'connected' ? 'up' : 'down',
                'ifSpeed' => ($iface['bandwidth'] ?? 0),
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['physicalAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
