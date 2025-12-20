<?php

namespace LibreNMS\Util\Normalizers\Nutanix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Nutanix - Hosts Normalizer
 *
 * Capability: unknown
 * Vendor: nutanix
 */
class Hosts extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'nutanix';

    protected function doNormalize(Device $device, array $payload): array
    {
$processors = [];
        $mempools = [];
        $hosts = $payload['entities'] ?? [];

        foreach ($hosts as $idx => $host) {
            $cpuUsage = $host['stats']['hypervisor_cpu_usage_ppm'] ?? 0;
            $cpuUsagePercent = $cpuUsage / 10000;

            $processors[] = [
                'processor_index' => $idx,
                'processor_type' => 'nutanix-host',
                'processor_descr' => $host['name'] ?? "Host $idx",
                'processor_usage' => $cpuUsagePercent,
            ];

            $memTotal = $host['memory_capacity_in_bytes'] ?? 0;
            $memUsed = $host['stats']['hypervisor_memory_usage_ppm'] ?? 0;
            $memUsedBytes = ($memTotal * $memUsed) / 1000000;

            $mempools[] = [
                'mempool_index' => $idx,
                'mempool_type' => 'nutanix',
                'mempool_descr' => $host['name'] ?? "Host $idx",
                'mempool_total' => $memTotal,
                'mempool_used' => $memUsedBytes,
                'mempool_free' => $memTotal - $memUsedBytes,
                'mempool_perc' => $memUsed / 10000,
            ];
        }

        return ['processors' => $processors, 'mempools' => $mempools];
    }
}
