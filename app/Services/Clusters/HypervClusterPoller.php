<?php

namespace App\Services\Clusters;

use App\Models\Cluster;
use App\Models\ClusterMetric;
use App\Models\ClusterNode;

/**
 * Placeholder poller for Hyper-V clusters. Implement WMI/REST retrieval.
 */
class HypervClusterPoller implements ClusterPollerInterface
{
    public function poll(int $deviceId): array
    {
        $cluster = $this->upsertCluster($deviceId);

        $nodesData = [
            ['node_name' => 'hyperv-01', 'role' => 'host', 'effective' => true, 'cpu_total_mhz' => 40000, 'memory_total_mb' => 131072, 'state' => 'up'],
            ['node_name' => 'hyperv-02', 'role' => 'host', 'effective' => true, 'cpu_total_mhz' => 40000, 'memory_total_mb' => 131072, 'state' => 'up'],
        ];

        $metricsData = [
            'timestamp' => now(),
            'cpu_total_mhz' => 80000,
            'cpu_usage_pct' => 55.0,
            'memory_total_mb' => 262144,
            'memory_usage_pct' => 65.0,
            'storage_total_bytes' => 30 * 1024 * 1024 * 1024 * 1024,
            'storage_used_bytes' => 18 * 1024 * 1024 * 1024 * 1024,
        ];

        $nodesUpserted = 0;
        foreach ($nodesData as $node) {
            ClusterNode::updateOrCreate(
                ['cluster_id' => $cluster->id, 'node_name' => $node['node_name']],
                $node
            );
            $nodesUpserted++;
        }

        ClusterMetric::create(array_merge(['cluster_id' => $cluster->id], $metricsData));

        return ['clusters' => 1, 'nodes' => $nodesUpserted, 'metrics' => 1];
    }

    public function upsertCluster(int $deviceId): Cluster
    {
        return Cluster::updateOrCreate(
            ['device_id' => $deviceId, 'provider_type' => 'hyperv', 'cluster_name' => 'Hyper-V Cluster'],
            ['environment' => 'prod', 'state' => 'healthy']
        );
    }
}
