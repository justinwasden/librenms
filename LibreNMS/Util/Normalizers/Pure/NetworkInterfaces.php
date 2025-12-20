<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - NetworkInterfaces Normalizer
 *
 * Capability: ports
 * Vendor: pure
 */
class NetworkInterfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $ports;
        }

        foreach ($payload['items'] as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";
            $enabled = ($iface['enabled'] ?? false) ? 'up' : 'down';
            $speed = $iface['speed'] ?? 0;

            // Pure Storage appears to return speed already in bits per second
            // Cap at max BIGINT value to avoid database overflow (use 2^63-1 as safe limit)
            $speedBps = min($speed, 9223372036854775807);

            $ports[] = [
                'ifIndex' => $this->stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $speedBps,
                'ifOperStatus' => $enabled,
                'ifAdminStatus' => $enabled,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['hwaddr'] ?? '',
                'ifAlias' => $iface['description'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }
}
