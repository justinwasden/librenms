<?php

namespace LibreNMS\Util\Normalizers\PaloAlto;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * PaloAlto - System Normalizer
 *
 * Capability: device_info
 * Vendor: pan
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'pan';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $system = $payload['result']['system'] ?? [];

        // Session count
        if (isset($system['num-active-sessions'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'paloalto',
                'sensor_descr' => 'Active Sessions',
                'sensor_index' => 'sessions',
                'sensor_current' => $system['num-active-sessions'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }
}
