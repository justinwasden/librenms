<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - HrDevice Normalizer
 *
 * Capability: unknown
 * Vendor: generic
 */
class HrDevice extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$devices = [];
        $items = $payload['items'] ?? $payload['devices'] ?? $payload;

        foreach ($items as $device) {
            $devices[] = [
                'hrDeviceIndex'    => $device['index'] ?? $device['device_index'] ?? $this->stableIndexFromName($device['name'] ?? 'device'),
                'hrDeviceDescr'    => $device['descr'] ?? $device['description'] ?? $device['name'] ?? 'Unknown Device',
                'hrDeviceType'     => $device['type'] ?? $device['device_type'] ?? 'unknown',
                'hrDeviceStatus'   => $device['status'] ?? 'unknown',
                'hrDeviceErrors'   => $device['errors'] ?? $device['error_count'] ?? 0,
                'hrProcessorLoad'  => $device['processor_load'] ?? $device['cpu_load'] ?? null,
            ];
        }

        return $devices;
    }
}
