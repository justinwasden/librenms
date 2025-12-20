<?php

namespace LibreNMS\Util\Normalizers\Calix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Calix - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: calix
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'calix';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        if (isset($payload['subscribers'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'calix',
                'sensor_descr' => 'Total Subscribers',
                'sensor_index' => 'subscribers',
                'sensor_current' => $payload['subscribers'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
