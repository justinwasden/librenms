<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - InterfacesToPorts Normalizer
 *
 * Capability: ports
 * Vendor: isilon
 */
class InterfacesToPorts extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'isilon';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $list = $payload['interfaces'] ?? $payload['items'] ?? [];

        foreach ($list as $idx => $iface) {
            $name = $iface['name'] ?? ("iface_$idx");
            $index = $this->stableIndexFromName($name);

            $ports[] = [
                'ifIndex'       => $index,
                'ifName'        => $name,
                'ifDescr'       => $iface['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($iface['speed'] ?? 1000000000),
                'ifOperStatus'  => $this->toStatus($iface['status'] ?? 'up'),
                'ifAdminStatus' => $this->toStatus($iface['enabled'] ?? true),
                'ifMtu'         => (int)($iface['mtu'] ?? 1500),
                'ifPhysAddress' => $iface['mac'] ?? '',
                'ifAlias'       => $iface['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }
}
