<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Mempools Normalizer
 *
 * Capability: mempools
 * Vendor: velocloud
 */
class Mempools extends BaseNormalizer
{
    protected string $capability = 'mempools';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $mempools = [];

        if (!is_array($data)) {
            return [];
        }

        // Handle metrics/getEdgeStatusMetrics format (memoryPct with min/max/average)
        if (isset($data['memoryPct']) && is_array($data['memoryPct'])) {
            $memoryUsagePct = $data['memoryPct']['average'] ?? $data['memoryPct']['max'] ?? null;
            if ($memoryUsagePct !== null) {
                // VeloCloud doesn't report total memory in status metrics, so we estimate
                // Typical edge has 4GB-16GB RAM depending on model
                // We'll use percentage-based tracking without total
                $mempools[] = [
                    'mempool_index' => 'edge-memory',
                    'mempool_type' => 'velocloud-edge',
                    'mempool_descr' => 'Edge Memory',
                    'mempool_perc' => $memoryUsagePct,
                    'mempool_perc_warn' => 80,
                ];
            }
            return $mempools;
        }

        // Handle legacy format - Get edge metrics if available
        $edgeMetrics = $data['edgeMetrics'] ?? $data['edges'] ?? [];
        if (!is_array($edgeMetrics)) {
            return [];
        }

        foreach ($edgeMetrics as $idx => $edge) {
            $edgeName = $edge['edgeName'] ?? "Edge-{$idx}";
            $memoryUsagePct = $edge['memoryPercentage'] ?? $edge['memoryPct'] ?? null;
            $memoryTotal = $edge['memoryTotal'] ?? null;

            if ($memoryUsagePct !== null && $memoryTotal !== null) {
                $memUsed = ($memoryTotal * $memoryUsagePct) / 100;
                $memFree = $memoryTotal - $memUsed;

                $mempools[] = [
                    'mempool_index' => "edge-{$idx}",
                    'mempool_type' => 'velocloud-edge',
                    'mempool_descr' => "{$edgeName} Memory",
                    'mempool_total' => $memoryTotal,
                    'mempool_used' => $memUsed,
                    'mempool_free' => $memFree,
                    'mempool_perc' => $memoryUsagePct,
                ];
            } elseif ($memoryUsagePct !== null) {
                // No total available, use percentage only
                $mempools[] = [
                    'mempool_index' => "edge-{$idx}",
                    'mempool_type' => 'velocloud-edge',
                    'mempool_descr' => "{$edgeName} Memory",
                    'mempool_perc' => $memoryUsagePct,
                    'mempool_perc_warn' => 80,
                ];
            }
        }

        return $mempools;
    }
}
