<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - Dhcp Normalizer
 *
 * Capability: unknown
 * Vendor: fortgate
 */
class Dhcp extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'fortgate';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        $leaseCount = count($results);

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'DHCP Leases Active',
            'sensor_index' => 'dhcp_leases',
            'sensor_current' => $leaseCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }
}
