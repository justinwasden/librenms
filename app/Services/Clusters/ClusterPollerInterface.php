<?php

namespace App\Services\Clusters;

use App\Models\Cluster;

/**
 * Interface for cluster pollers.
 */
interface ClusterPollerInterface
{
    /**
     * Poll cluster data for a device and upsert into the unified schema.
     *
     * @param int $deviceId
     * @return array{clusters: int, nodes: int, metrics: int} Count of upserted records
     */
    public function poll(int $deviceId): array;

    /**
     * Upsert cluster record.
     *
     * @param int $deviceId
     * @return Cluster
     */
    public function upsertCluster(int $deviceId): Cluster;
}
