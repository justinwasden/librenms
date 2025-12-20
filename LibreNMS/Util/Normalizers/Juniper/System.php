<?php

namespace LibreNMS\Util\Normalizers\Juniper;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Juniper - System Normalizer
 *
 * Capability: device_info
 * Vendor: junos
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'junos';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $processors = [];

        // CPU usage
        if (isset($payload['system-cpu-information'])) {
            $cpuInfo = $payload['system-cpu-information'];
            $cpuUsage = $cpuInfo['cpu-usage'] ?? 0;

            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'junos-cpu',
                'processor_descr' => 'System CPU',
                'processor_usage' => $cpuUsage,
            ];
        }

        // Memory usage
        if (isset($payload['system-memory-information'])) {
            $memInfo = $payload['system-memory-information'];
            $memTotal = $memInfo['memory-total'] ?? 100;
            $memUsed = $memInfo['memory-used'] ?? 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'junos',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'mem_usage',
                'sensor_current' => $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0,
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        return ['sensors' => $sensors, 'processors' => $processors];
    }
}
