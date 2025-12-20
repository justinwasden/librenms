<?php

namespace LibreNMS\Util\Normalizers\Dell;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Dell - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: dell
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'dell';

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
                        'sensor_type' => 'dell',
                        'sensor_descr' => $temp['Name'] ?? "Temperature $idx",
                        'sensor_index' => "temp-$idx",
                        'sensor_current' => $temp['ReadingCelsius'],
                        'sensor_limit' => $temp['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $temp['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        // Fan sensors
        if (isset($thermalData['Fans'])) {
            foreach ($thermalData['Fans'] as $idx => $fan) {
                if (isset($fan['Reading'])) {
                    $sensors[] = [
                        'sensor_class' => 'fanspeed',
                        'sensor_type' => 'dell',
                        'sensor_descr' => $fan['Name'] ?? "Fan $idx",
                        'sensor_index' => "fan-$idx",
                        'sensor_current' => $fan['Reading'],
                        'sensor_limit' => $fan['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $fan['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        return ['sensors' => $sensors];
    }
}
