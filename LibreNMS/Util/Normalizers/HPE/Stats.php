<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - Stats Normalizer
 *
 * Capability: unknown
 * Vendor: nimble
 */
class Stats extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'nimble';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        if (isset($payload['volume_stats'])) {
            $stats = $payload['volume_stats'];
            $iops = $stats['iops'] ?? 0;
            $throughput = $stats['throughput'] ?? 0;

            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'nimble',
                'sensor_descr' => 'IOPS',
                'sensor_index' => 'iops',
                'sensor_current' => $iops,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
