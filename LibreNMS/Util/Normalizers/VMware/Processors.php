<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Processors Normalizer
 *
 * Capability: processors
 * Vendor: velocloud
 */
class Processors extends BaseNormalizer
{
    protected string $capability = 'processors';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $processors = [];

        if (!is_array($data)) {
            return [];
        }

        // Handle metrics/getEdgeStatusMetrics format (cpuPct with min/max/average)
        if (isset($data['cpuPct']) && is_array($data['cpuPct'])) {
            $cpuUsage = $data['cpuPct']['average'] ?? $data['cpuPct']['max'] ?? null;
            if ($cpuUsage !== null) {
                $processors[] = [
                    'processor_index' => 'edge-cpu',
                    'processor_type' => 'velocloud-edge-cpu',
                    'processor_descr' => 'Edge CPU',
                    'processor_usage' => $cpuUsage,
                ];
            }
            return $processors;
        }

        // Handle legacy format - Get edge metrics if available
        $edgeMetrics = $data['edgeMetrics'] ?? $data['edges'] ?? [];
        if (!is_array($edgeMetrics)) {
            return [];
        }

        foreach ($edgeMetrics as $idx => $edge) {
            $edgeName = $edge['edgeName'] ?? "Edge-{$idx}";
            $cpuUsage = $edge['cpuPercentage'] ?? $edge['cpuPct'] ?? null;

            if ($cpuUsage !== null) {
                $processors[] = [
                    'processor_index' => "edge-{$idx}",
                    'processor_type' => 'velocloud-edge-cpu',
                    'processor_descr' => "{$edgeName} CPU",
                    'processor_usage' => $cpuUsage,
                ];
            }
        }

        return $processors;
    }
}
