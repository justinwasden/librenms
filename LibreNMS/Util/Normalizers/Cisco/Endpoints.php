<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - Endpoints Normalizer
 *
 * Capability: unknown
 * Vendor: ise
 */
class Endpoints extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'ise';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $endpoints = $payload['SearchResult']['resources'] ?? [];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'cisco-ise',
            'sensor_descr' => 'Total Endpoints',
            'sensor_index' => 'endpoints-total',
            'sensor_current' => count($endpoints),
            'sensor_limit' => null,
            'sensor_limit_low' => null,
        ];

        return ['sensors' => $sensors];
    }
}
