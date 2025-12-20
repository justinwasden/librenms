<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - License Normalizer
 *
 * Capability: unknown
 * Vendor: fortgate
 */
class License extends BaseNormalizer
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

        foreach ($results as $license) {
            $name = $license['name'] ?? 'Unknown';
            $type = $license['type'] ?? '';
            $status = $license['status'] ?? 'unknown';

            // Skip if no name
            if (!$name || $name === 'Unknown') {
                continue;
            }

            // Create sensor index from name
            $index = $this->stableIndexFromName($name);

            // Map status to numeric value
            $statusValue = match (strtolower($status)) {
                'valid', 'licensed' => 1,
                'expired' => 2,
                'invalid' => 3,
                default => 0, // unknown
            };

            // Add license status as state sensor
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'fortigate',
                'sensor_descr' => 'License ' . $name,
                'sensor_index' => 'license_' . $index,
                'sensor_current' => $statusValue,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'valid'],
                    ['value' => 2, 'generic' => 2, 'graph' => 0, 'descr' => 'expired'],
                    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'invalid'],
                ],
            ];

            // If there's a days remaining field, add it as count sensor
            if (isset($license['days']) && is_numeric($license['days'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'Days left for ' . $name,
                    'sensor_index' => 'license_days_' . $index,
                    'sensor_current' => (int)$license['days'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }
}
