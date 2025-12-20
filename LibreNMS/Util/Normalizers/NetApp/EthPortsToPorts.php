<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - EthPortsToPorts Normalizer
 *
 * Capability: ports
 * Vendor: unity
 */
class EthPortsToPorts extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'unity';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $p = $entry['content'] ?? $entry;
            $name = $p['name'] ?? ($p['id'] ?? 'ethPort');
            $index = $this->stableIndexFromName($name);

            $ports[] = [
                'ifIndex'       => $index,
                'ifName'        => $name,
                'ifDescr'       => $p['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($p['speed'] ?? 1000000000),
                'ifOperStatus'  => $this->toStatus($p['linkStatus'] ?? ($p['enabled'] ?? true)),
                'ifAdminStatus' => $this->toStatus($p['enabled'] ?? true),
                'ifMtu'         => (int)($p['mtu'] ?? 1500),
                'ifPhysAddress' => $p['macAddress'] ?? '',
                'ifAlias'       => $p['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }
}
