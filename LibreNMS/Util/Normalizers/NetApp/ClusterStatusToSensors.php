<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - ClusterStatusToSensors Normalizer
 *
 * Capability: sensors
 * Vendor: isilon
 */
class ClusterStatusToSensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'isilon';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $status = $payload['status'] ?? $payload;

        if (isset($status['quorum'])) {
            $sensors[] = [
                'sensor_class'   => 'state',
                'sensor_type'    => 'isilon',
                'sensor_descr'   => 'Cluster Quorum',
                'sensor_index'   => 'isilon_cluster_quorum',
                'sensor_current' => $status['quorum'] ? 2 : 0,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'no quorum'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'quorate'],
                ],
            ];
        }

        if (isset($status['nodes'])) {
            $sensors[] = [
                'sensor_class'   => 'count',
                'sensor_type'    => 'isilon',
                'sensor_descr'   => 'Cluster Nodes',
                'sensor_index'   => 'isilon_cluster_nodes',
                'sensor_current' => (int)$status['nodes'],
                'sensor_limit'   => null,
                'sensor_limit_low' => 1,
            ];
        }

        return $sensors;
    }
}
