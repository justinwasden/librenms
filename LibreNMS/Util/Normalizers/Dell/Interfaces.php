<?php

namespace LibreNMS\Util\Normalizers\Dell;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Dell - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: dell
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'dell';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $interfaces = $payload['NetworkInterfaces'] ?? $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['Id'] ?? $iface['Name'] ?? "interface-$idx";
            $status = strtolower($iface['Status']['State'] ?? $iface['LinkStatus'] ?? 'unknown');

            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['Description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($status === 'enabled' || $status === 'up') ? 'up' : 'down',
                'ifAdminStatus' => ($status === 'enabled' || $status === 'up') ? 'up' : 'down',
                'ifSpeed' => ($iface['SpeedMbps'] ?? 0) * 1000000,
                'ifMtu' => $iface['MTUSize'] ?? 1500,
                'ifPhysAddress' => $iface['MACAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }
}
