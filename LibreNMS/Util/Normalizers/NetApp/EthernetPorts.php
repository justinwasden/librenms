<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - EthernetPorts Normalizer
 *
 * Capability: ports
 * Vendor: ontap
 */
class EthernetPorts extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'ontap';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $idx => $p) {
            $name = $p['name'] ?? ("port_$idx");
            $ifIndex = $this->stableIndexFromName($name);
            $ports[] = [
                'ifIndex'       => $ifIndex,
                'ifName'        => $name,
                'ifDescr'       => $p['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($p['speed'] ?? 0),
                'ifOperStatus'  => $this->toStatus($p['enabled'] ?? true),
                'ifAdminStatus' => $this->toStatus($p['enabled'] ?? true),
                'ifMtu'         => (int)($p['mtu'] ?? 1500),
                'ifPhysAddress' => $p['mac'] ?? '',
                'ifAlias'       => $p['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }
}
