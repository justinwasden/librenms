<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - VpnIpsec Normalizer
 *
 * Capability: unknown
 * Vendor: fortigate
 */
class VpnIpsec extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        // Count active and total tunnels
        $activeCount = 0;
        $totalCount = count($results);

        foreach ($results as $tunnel) {
            $status = $tunnel['status'] ?? 'down';
            if (in_array(strtolower($status), ['up', 'established'])) {
                $activeCount++;
            }
        }

        // Add count sensors for VPN tunnels
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'IPsec VPN Tunnels Active',
            'sensor_index' => 'ipsec_active',
            'sensor_current' => $activeCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'IPsec VPN Tunnels Total',
            'sensor_index' => 'ipsec_total',
            'sensor_current' => $totalCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }
}
