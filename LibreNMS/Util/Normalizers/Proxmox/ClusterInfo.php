<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - ClusterInfo Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class ClusterInfo extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $clusters = [];

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $item) {
            $type = $item['type'] ?? null;

            // The first item with type 'cluster' represents the cluster itself
            if ($type === 'cluster') {
                $clusters[] = [
                    'cluster_type' => 'proxmox',
                    'cluster_id' => $item['name'] ?? 'proxmox-cluster',
                    'cluster_name' => $item['name'] ?? 'Proxmox Cluster',
                    'parent_id' => null,
                    'parent_name' => null,
                    'cluster_level' => 'cluster',
                    'metadata' => [
                        'quorate' => $item['quorate'] ?? null,
                        'nodes' => $item['nodes'] ?? null,
                        'version' => $item['version'] ?? null,
                    ],
                ];
                break;
            }
        }

        // If no cluster found, create a default standalone entry
        if (empty($clusters)) {
            $clusters[] = [
                'cluster_type' => 'proxmox',
                'cluster_id' => 'standalone',
                'cluster_name' => 'Standalone Node',
                'parent_id' => null,
                'parent_name' => null,
                'cluster_level' => 'cluster',
                'metadata' => [],
            ];
        }

        return $clusters;
    }
}
