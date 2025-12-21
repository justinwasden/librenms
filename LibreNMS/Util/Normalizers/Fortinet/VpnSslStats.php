<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - VpnSslStats Normalizer
 *
 * Capability: vpn-ssl-stats
 * Vendor: fortigate
 */
class VpnSslStats extends BaseNormalizer
{
    protected string $capability = 'vpn-ssl-stats';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
        $sensors = [];

        $results = $payload['results'] ?? $payload;

        foreach ($results as $entry) {
            if (empty($entry['username'])) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'traffic',
                'sensor_type' => 'fortinet-vpn-ssl-stats',
                'sensor_descr' => 'VPN SSL: ' . $entry['username'],
                'sensor_index' => $entry['username'],
                'sensor_current' => $entry['bytes_in'],
                'sensor_prev' => $entry['bytes_out'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
