<?php

namespace LibreNMS\Util\Normalizers\SonicWall;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * SonicWall - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: sonic
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'sonic';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        if (isset($payload['connections'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'sonicwall',
                'sensor_descr' => 'Active Connections',
                'sensor_index' => 'connections',
                'sensor_current' => $payload['connections'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
