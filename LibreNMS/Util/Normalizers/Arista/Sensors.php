<?php

namespace LibreNMS\Util\Normalizers\Arista;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Arista - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: arista
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'arista';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $temps = $payload['tempSensors'] ?? [];

        foreach ($temps as $name => $temp) {
            $sensors[] = [
                'sensor_class' => 'temperature',
                'sensor_type' => 'arista',
                'sensor_descr' => $name,
                'sensor_index' => $name,
                'sensor_current' => $temp['currentTemperature'] ?? 0,
                'sensor_limit' => $temp['maxTemperature'] ?? null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
