<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: hpe
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'hpe';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $thermalData = $payload['Thermal'] ?? [];

        // Temperature sensors
        if (isset($thermalData['Temperatures'])) {
            foreach ($thermalData['Temperatures'] as $idx => $temp) {
                if (isset($temp['ReadingCelsius'])) {
                    $sensors[] = [
                        'sensor_class' => 'temperature',
                        'sensor_type' => 'hpe',
                        'sensor_descr' => $temp['Name'] ?? "Temperature $idx",
                        'sensor_index' => "temp-$idx",
                        'sensor_current' => $temp['ReadingCelsius'],
                        'sensor_limit' => $temp['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $temp['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        return ['sensors' => $sensors];
    }
}
