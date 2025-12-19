<?php

namespace App\Services\Clusters;

use App\Models\Cluster;
use App\Models\ClusterMetric;
use App\Models\ClusterNode;

/**
 * Placeholder poller for storage arrays (e.g., Pure Storage). Implement vendor API calls.
 */
class StorageArrayClusterPoller implements ClusterPollerInterface
{
    public function poll(int $deviceId): array
    {
        $cluster = $this->upsertCluster($deviceId);

        $nodesData = [
            ['node_name' => 'array-ctrl-a', 'role' => 'controller', 'effective' => true, 'state' => 'up'],
            ['node_name' => 'array-ctrl-b', 'role' => 'controller', 'effective' => true, 'state' => 'up'],
        ];

        $metricsData = [
            'timestamp' => now(),
            'storage_total_bytes' => 100 * 1024 * 1024 * 1024 * 1024,
            'storage_effective_bytes' => 95 * 1024 * 1024 * 1024 * 1024,
            'storage_used_bytes' => 60 * 1024 * 1024 * 1024 * 1024,
            'storage_usage_pct' => 63.16,
            'storage_iops_read' => 50000,
            'storage_iops_write' => 35000,
            'storage_bw_read_mbps' => 10000,
            'storage_bw_write_mbps' => 7000,
            'storage_latency_read_us' => 250,
            'storage_latency_write_us' => 300,
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
            ['device_id' => $deviceId, 'provider_type' => 'storage_array', 'cluster_name' => 'Storage Cluster'],
            ['environment' => 'prod', 'state' => 'healthy']
        );
    }
}
