<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - Ipv4 Normalizer
 *
 * Capability: ipv4
 * Vendor: fortigate
 */
class Ipv4 extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$addresses = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return $addresses;
        }

        foreach ($results as $iface) {
            $ifName = $iface['name'] ?? '';
            $ip = $iface['ip'] ?? $iface['ipv4'] ?? '';

            if (!$ip || $ip === '0.0.0.0') {
                continue;
            }

            // Parse IP/CIDR
            if (strpos($ip, '/') !== false) {
                [$ipAddr, $prefixLen] = explode('/', $ip, 2);
            } else {
                $ipAddr = $ip;
                $prefixLen = isset($iface['netmask']) && $iface['netmask'] ? $this->netmaskToCidr($iface['netmask']) : 24;
            }

            $addresses[] = [
                'ifIndex' => $this->stableIndexFromName($ifName),
                'ipv4_address' => $ipAddr,
                'ipv4_prefixlen' => $prefixLen,
                'context_name' => '',
            ];
        }

        return $addresses;
    }
}
