<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - VolumePerfByArray Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class VolumePerfByArray extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $perf) {
            $name = $perf['name'] ?? 'volume';
            $index = $this->stableIndexFromName($name);

            if (isset($perf['queue_depth'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Queue Depth',
                    'sensor_index' => 'vol_queue_' . $index,
                    'sensor_current' => $perf['queue_depth'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }
}
