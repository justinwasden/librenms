<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - NodeMetricsToProcessorsMempools Normalizer
 *
 * Capability: processors
 * Vendor: ontap
 */
class NodeMetricsToProcessorsMempools extends BaseNormalizer
{
    protected string $capability = 'processors';
    protected string $vendor = 'ontap';

    protected function doNormalize(Device $device, array $payload): array
    {
$processors = [];
        $mempools = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = $this->stableIndexFromName($name);

            $cpuPct = null;
            if (isset($node['cpu_utilization']['percent'])) {
                $cpuPct = (float)$node['cpu_utilization']['percent'];
            } elseif (isset($node['cpu']['percent'])) {
                $cpuPct = (float)$node['cpu']['percent'];
            } elseif (isset($node['cpu'])) {
                $cpu = $node['cpu'];
                $cpuPct = is_array($cpu) && isset($cpu['overall']) ? (float)$cpu['overall'] : (is_numeric($cpu) ? (float)$cpu : null);
            }

            if ($cpuPct !== null) {
                $processors[] = [
                    'processor_index' => $index,
                    'processor_type' => 'netapp-cpu',
                    'processor_descr' => "Node $name CPU",
                    'processor_usage' => round($cpuPct, 2),
                ];
            }

            $memTotal = null;
            $memUsed = null;
            if (isset($node['memory']['total'])) {
                $memTotal = (int)$node['memory']['total'];
                $memUsed  = (int)($node['memory']['used'] ?? 0);
            } elseif (isset($node['memory_total'])) {
                $memTotal = (int)$node['memory_total'];
                $memUsed  = (int)($node['memory_used'] ?? 0);
            }

            if ($memTotal && $memTotal > 0) {
                $mempools[] = [
                    'mempool_index' => $index,
                    'mempool_type' => 'netapp',
                    'mempool_descr' => "Node $name Memory",
                    'mempool_used' => $memUsed ?? 0,
                    'mempool_free' => $memTotal - ($memUsed ?? 0),
                    'mempool_total' => $memTotal,
                    'mempool_perc' => round(($memUsed ?? 0) / $memTotal * 100, 2),
                ];
            }
        }

        return ['processors' => $processors, 'mempools' => $mempools];
    }
}
