<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - ResourcesToSensors Normalizer
 *
 * Capability: sensors
 * Vendor: unity
 */
class ResourcesToSensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'unity';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $res = $entry['content'] ?? $entry;
            $name = $res['name'] ?? 'resource';
            $index = $this->stableIndexFromName($name);
            $total = (int)($res['sizeTotal'] ?? 0);
            $used  = (int)($res['sizeUsed'] ?? 0);

            if ($total > 0) {
                $pct = ($used / $total) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'unity',
                    'sensor_descr'   => "Resource $name Used",
                    'sensor_index'   => "unity_res_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }
}
