<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - SystemMetrics Normalizer
 *
 * Capability: device_info
 * Vendor: velocloud
 */
class SystemMetrics extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $sensors = [];

        if (!is_array($data)) {
            return [];
        }

        // Flow count sensor - use max for instantaneous count, cast to int
        if (isset($data['flowCount']) && is_array($data['flowCount'])) {
            $flowCount = $data['flowCount']['max'] ?? $data['flowCount']['average'] ?? null;
            if ($flowCount !== null) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'velocloud_flows',
                    'sensor_descr' => 'Active Flows',
                    'sensor_index' => 'edge-flows',
                    'sensor_current' => (int) round($flowCount),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        // Tunnel count sensor (IPv4) - use max for instantaneous count, cast to int
        if (isset($data['tunnelCount']) && is_array($data['tunnelCount'])) {
            $tunnelCount = $data['tunnelCount']['max'] ?? $data['tunnelCount']['average'] ?? null;
            if ($tunnelCount !== null) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'velocloud_tunnels',
                    'sensor_descr' => 'Active Tunnels (IPv4)',
                    'sensor_index' => 'edge-tunnels-v4',
                    'sensor_current' => (int) round($tunnelCount),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        // Tunnel count sensor (IPv6) - use max for instantaneous count, cast to int
        if (isset($data['tunnelCountV6']) && is_array($data['tunnelCountV6'])) {
            $tunnelCountV6 = $data['tunnelCountV6']['max'] ?? $data['tunnelCountV6']['average'] ?? null;
            if ($tunnelCountV6 !== null && $tunnelCountV6 > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'velocloud_tunnels',
                    'sensor_descr' => 'Active Tunnels (IPv6)',
                    'sensor_index' => 'edge-tunnels-v6',
                    'sensor_current' => (int) round($tunnelCountV6),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        // Handoff queue drops (over-capacity drops) - cast to int
        if (isset($data['handoffQueueDrops']) && is_array($data['handoffQueueDrops'])) {
            $drops = $data['handoffQueueDrops']['max'] ?? $data['handoffQueueDrops']['average'] ?? null;
            if ($drops !== null) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'velocloud_drops',
                    'sensor_descr' => 'Handoff Queue Drops',
                    'sensor_index' => 'edge-handoff-drops',
                    'sensor_current' => (int) round($drops),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }
}
