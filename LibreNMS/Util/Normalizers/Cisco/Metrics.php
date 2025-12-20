<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - Metrics Normalizer
 *
 * Capability: unknown
 * Vendor: ftd
 */
class Metrics extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'ftd';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        // FTD metrics can include CPU, memory, connections, throughput
        if (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $metric) {
                $metricType = $metric['metricType'] ?? null;
                $metricName = $metric['name'] ?? $metricType;
                $value = $metric['value'] ?? $metric['currentValue'] ?? null;

                if ($value === null) {
                    continue;
                }

                // CPU usage
                if (stripos($metricType, 'cpu') !== false) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'ftd-cpu',
                        'sensor_descr' => $metricName,
                        'sensor_index' => 'ftd_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($metricType)),
                        'sensor_current' => $value,
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Memory usage
                if (stripos($metricType, 'memory') !== false || stripos($metricType, 'mem') !== false) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'ftd-memory',
                        'sensor_descr' => $metricName,
                        'sensor_index' => 'ftd_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($metricType)),
                        'sensor_current' => $value,
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Connection counts
                if (stripos($metricType, 'connection') !== false || stripos($metricType, 'conn') !== false) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'ftd-connections',
                        'sensor_descr' => $metricName,
                        'sensor_index' => 'ftd_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($metricType)),
                        'sensor_current' => $value,
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Throughput metrics (bps)
                if (stripos($metricType, 'throughput') !== false || stripos($metricType, 'bps') !== false) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'ftd-throughput',
                        'sensor_descr' => $metricName,
                        'sensor_index' => 'ftd_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($metricType)),
                        'sensor_current' => $value,
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        } elseif (isset($payload['cpu']) || isset($payload['memory'])) {
            // Direct metrics in payload
            if (isset($payload['cpu'])) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'ftd-cpu',
                    'sensor_descr' => 'CPU Usage',
                    'sensor_index' => 'ftd_cpu_usage',
                    'sensor_current' => $payload['cpu'],
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            if (isset($payload['memory'])) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'ftd-memory',
                    'sensor_descr' => 'Memory Usage',
                    'sensor_index' => 'ftd_memory_usage',
                    'sensor_current' => $payload['memory'],
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }
}
