<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - HrSystem Normalizer
 *
 * Capability: device_info
 * Vendor: generic
 */
class HrSystem extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['system'] ?? $payload[0] ?? $payload;

        return [
            'hrSystemNumUsers'     => $data['num_users'] ?? $data['users'] ?? $data['user_count'] ?? 0,
            'hrSystemProcesses'    => $data['processes'] ?? $data['process_count'] ?? 0,
            'hrSystemMaxProcesses' => $data['max_processes'] ?? $data['process_limit'] ?? 0,
        ];
    }
}
