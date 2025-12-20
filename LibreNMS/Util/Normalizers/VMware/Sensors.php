<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Sensors Normalizer
 *
 * Capability: sensors
 * Vendor: velocloud
 */
class Sensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $sensors = [];

        if (!is_array($data)) {
            return [];
        }

        $links = $data['links'] ?? [];
        if (!is_array($links)) {
            return [];
        }

        foreach ($links as $idx => $link) {
            $linkName = $link['link'] ?? "Link-{$idx}";
            $linkId = $link['linkId'] ?? $idx;

            // Link state sensor
            if (isset($link['state'])) {
                $stateMap = [
                    'STABLE' => 2,
                    'UP' => 2,
                    'UNSTABLE' => 1,
                    'DOWN' => 0,
                    'DEAD' => 0,
                ];
                $state = strtoupper($link['state']);
                $stateValue = $stateMap[$state] ?? 3;

                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} State",
                    'sensor_index' => "link-{$linkId}-state",
                    'sensor_current' => $stateValue,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'down'],
                        ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unstable'],
                        ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'stable'],
                        ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ],
                ];
            }

            // Packet loss percentage
            if (isset($link['bestLossPercentage'])) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} Packet Loss",
                    'sensor_index' => "link-{$linkId}-loss",
                    'sensor_current' => round($link['bestLossPercentage'], 2),
                    'sensor_limit' => 5,
                    'sensor_limit_low' => 0,
                ];
            }

            // Latency (ms)
            if (isset($link['bestLatencyMsec'])) {
                $sensors[] = [
                    'sensor_class' => 'delay',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} Latency",
                    'sensor_index' => "link-{$linkId}-latency",
                    'sensor_current' => $link['bestLatencyMsec'],
                    'sensor_limit' => 150,
                    'sensor_limit_low' => 0,
                ];
            }

            // Jitter (ms)
            if (isset($link['bestJitterMsec'])) {
                $sensors[] = [
                    'sensor_class' => 'delay',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} Jitter",
                    'sensor_index' => "link-{$linkId}-jitter",
                    'sensor_current' => $link['bestJitterMsec'],
                    'sensor_limit' => 30,
                    'sensor_limit_low' => 0,
                ];
            }

            // Bandwidth utilization percentage
            if (isset($link['bandwidthUtilization'])) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} Bandwidth Utilization",
                    'sensor_index' => "link-{$linkId}-bw-util",
                    'sensor_current' => round($link['bandwidthUtilization'], 2),
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            // Signal strength (if available)
            if (isset($link['signalStrength'])) {
                $sensors[] = [
                    'sensor_class' => 'dbm',
                    'sensor_type' => 'velocloud',
                    'sensor_descr' => "{$linkName} Signal Strength",
                    'sensor_index' => "link-{$linkId}-signal",
                    'sensor_current' => $link['signalStrength'],
                    'sensor_limit' => -50,
                    'sensor_limit_low' => -90,
                ];
            }
        }

        return $sensors;
    }
}
