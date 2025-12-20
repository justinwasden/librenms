<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - SystemUsage Normalizer
 *
 * Capability: device_info
 * Vendor: fortigate
 */
class SystemUsage extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $processors = [];
        $mempools = [];

        $results = $payload['results'] ?? $payload;

        // CPU usage - extract current value from array structure
        if (isset($results['cpu'])) {
            $cpuValue = $results['cpu'];

            // If cpu is an array with 'current' field, extract it
            if (is_array($cpuValue)) {
                if (isset($cpuValue[0]['current'])) {
                    $cpuValue = $cpuValue[0]['current'];
                } elseif (isset($cpuValue['current'])) {
                    $cpuValue = $cpuValue['current'];
                } else {
                    $cpuValue = null; // Skip if format is unexpected
                }
            }

            if ($cpuValue !== null && is_numeric($cpuValue)) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'CPU Usage',
                    'sensor_index' => 'cpu_usage',
                    'sensor_current' => $cpuValue,
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];

                $processors[] = [
                    'processor_index' => 0,
                    'processor_type' => 'fortigate-cpu',
                    'processor_descr' => 'System CPU',
                    'processor_usage' => $cpuValue,
                ];
            }
        }

        // Memory usage - extract current value from array structure
        if (isset($results['mem'])) {
            $memValue = $results['mem'];

            // If mem is an array with 'current' field, extract it
            if (is_array($memValue)) {
                if (isset($memValue[0]['current'])) {
                    $memValue = $memValue[0]['current'];
                } elseif (isset($memValue['current'])) {
                    $memValue = $memValue['current'];
                } else {
                    $memValue = null; // Skip if format is unexpected
                }
            }

            if ($memValue !== null && is_numeric($memValue)) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'Memory Usage',
                    'sensor_index' => 'mem_usage',
                    'sensor_current' => $memValue,
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];

                // Check if absolute memory values are provided (in KB, MB, or bytes)
                $memTotal = $results['mem_total'] ?? $results['memory_total'] ?? null;
                $memUsed = $results['mem_used'] ?? $results['memory_used'] ?? null;

                // If absolute values not available, use percentage-based approach
                // Scale to 100 for percentage representation (mem field is already a percentage)
                if ($memTotal === null || $memUsed === null || !is_numeric($memTotal) || !is_numeric($memUsed)) {
                    $memTotal = 100;
                    $memUsed = $memValue;
                }

                $mempools[] = [
                    'mempool_index' => 0,
                    'mempool_type' => 'fortigate',
                    'mempool_descr' => 'System Memory',
                    'mempool_total' => (int)$memTotal,
                    'mempool_used' => (int)$memUsed,
                    'mempool_free' => (int)$memTotal - (int)$memUsed,
                    'mempool_perc' => $memValue,
                ];
            }
        }

        return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
    }
}
