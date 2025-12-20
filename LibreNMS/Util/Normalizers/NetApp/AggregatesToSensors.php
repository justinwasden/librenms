<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - AggregatesToSensors Normalizer
 *
 * Capability: sensors
 * Vendor: ontap
 */
class AggregatesToSensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'ontap';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $aggr) {
            $name = $aggr['name'] ?? 'aggregate';
            $index = $this->stableIndexFromName($name);
            $size = (int)($aggr['space']['size'] ?? 0);
            $used = (int)($aggr['space']['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Aggregate $name Used",
                    'sensor_index'   => "ontap_aggr_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            $state = strtolower((string)($aggr['state'] ?? 'unknown'));
            $map = ['online' => 2, 'relocating' => 1, 'offline' => 0, 'unknown' => 3];
            $sensors[] = [
                'sensor_class'   => 'state',
                'sensor_type'    => 'netapp',
                'sensor_descr'   => "Aggregate $name State",
                'sensor_index'   => "ontap_aggr_state_$index",
                'sensor_current' => $map[$state] ?? 3,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'offline'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'relocating'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'online'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];
        }

        return $sensors;
    }
}
