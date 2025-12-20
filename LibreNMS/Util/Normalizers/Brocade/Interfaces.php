<?php

namespace LibreNMS\Util\Normalizers\Brocade;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Brocade - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: brocade
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'brocade';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['Response']['fibrechannel'] ?? [];

        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "port-$idx",
                'ifDescr' => $iface['user-friendly-name'] ?? "port-$idx",
                'ifType' => 'fibreChannel',
                'ifOperStatus' => ($iface['operational-state'] ?? 0) === 2 ? 'up' : 'down',
                'ifAdminStatus' => ($iface['enabled-state'] ?? 0) === 2 ? 'up' : 'down',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000000,
                'ifMtu' => 2112,
                'ifPhysAddress' => null,
            ];
        }

        return ['ports' => $ports];
    }
}
