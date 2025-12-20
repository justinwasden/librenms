<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - VpnSsl Normalizer
 *
 * Capability: unknown
 * Vendor: fortigate
 */
class VpnSsl extends BaseNormalizer
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

        $userCount = count($results);

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'SSL VPN Users Connected',
            'sensor_index' => 'ssl_vpn_users',
            'sensor_current' => $userCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }
}
