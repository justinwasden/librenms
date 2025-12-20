<?php

namespace LibreNMS\Util\Normalizers\Extreme;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Extreme - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: extreme
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'extreme';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        // Extreme sensor implementation depends on their specific API structure
        return ['sensors' => $sensors];
    }
}
