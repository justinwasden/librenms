<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - SensorInfo Normalizer
 *
 * Capability: unknown
 * Vendor: fortgate
 */
class SensorInfo extends BaseNormalizer
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

        foreach ($results as $sensor) {
            $name = $sensor['name'] ?? 'Unknown';
            $type = $sensor['type'] ?? 'unknown';
            $value = $sensor['value'] ?? 0;
            $alarm = $sensor['alarm'] ?? 0;

            // Determine sensor class based on type
            $sensorClass = match ($type) {
                'temperature' => 'temperature',
                'fan' => 'fanspeed',
                'voltage' => 'voltage',
                'wattage' => 'power',
                'power' => 'state',
                default => null,
            };

            if (!$sensorClass) {
                continue; // Skip unknown sensor types
            }

            // Create sensor index from name
            $index = $this->stableIndexFromName($name);

            if ($sensorClass === 'state') {
                // Power supply status sensor
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => $name,
                    'sensor_index' => 'power_' . $index,
                    'sensor_current' => (int)$value,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'failed'],
                        ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'ok'],
                    ],
                ];
            } else {
                // Regular numeric sensor
                $sensors[] = [
                    'sensor_class' => $sensorClass,
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => $name,
                    'sensor_index' => $type . '_' . $index,
                    'sensor_current' => (float)$value,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }
}
