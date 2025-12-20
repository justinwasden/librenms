<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Health Normalizer
 *
 * Capability: unknown
 * Vendor: esxi
 */
class Health extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'esxi';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        $value = $payload['value'] ?? $payload;

        // Overall system health
        if (isset($value['system_health'])) {
            $healthMap = ['green' => 2, 'yellow' => 1, 'orange' => 1, 'red' => 0, 'gray' => 3];
            $health = strtolower($value['system_health']);
            $healthValue = $healthMap[$health] ?? 3;

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'esxi',
                'sensor_descr' => 'System Health',
                'sensor_index' => 'system_health',
                'sensor_current' => $healthValue,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'red'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'yellow/orange'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'green'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'gray/unknown'],
                ],
            ];
        }

        return $sensors;
    }
}
