<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure Storage FlashArray - Array Sensors Normalizer
 *
 * Normalizes data from:
 * - /api/2.x/arrays (capacity, space, status)
 * - /api/2.x/arrays/performance (IOPS, bandwidth, latency)
 *
 * Produces: sensors, storage, mempools
 */
class ArraySensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'purestorage';

    /**
     * Normalize Pure Storage array sensors
     *
     * @param Device $device
     * @param array $payload Expects ['arrayPayload' => ..., 'perfPayload' => ...]
     * @return array Returns ['sensors' => [...], 'storage' => [...], 'mempools' => [...]]
     */
    protected function doNormalize(Device $device, array $payload): array
    {
        // Handle multiple payload formats
        $arrayPayload = $payload['arrayPayload'] ?? $payload;
        $perfPayload = $payload['perfPayload'] ?? [];

        $sensors = [];
        $storage = [];
        $mempools = [];

        // Process array capacity and space data
        $sensors = array_merge($sensors, $this->extractCapacitySensors($arrayPayload));
        $sensors = array_merge($sensors, $this->extractPerformanceSensors($perfPayload));
        $storage = $this->extractStorage($arrayPayload);
        $mempools = $this->extractMempools($perfPayload);

        Log::debug("Pure ArraySensors normalized for device {$device->device_id}", [
            'sensors_count' => count($sensors),
            'storage_count' => count($storage),
            'mempools_count' => count($mempools),
        ]);

        return [
            'sensors' => $sensors,
            'storage' => $storage,
            'mempools' => $mempools,
        ];
    }

    /**
     * Extract capacity-related sensors
     */
    private function extractCapacitySensors(array $arrayPayload): array
    {
        $sensors = [];

        if (!isset($arrayPayload['items']) || !is_array($arrayPayload['items'])) {
            return $sensors;
        }

        foreach ($arrayPayload['items'] as $array) {
            $arrayName = $array['name'] ?? 'array';

            // Total capacity sensor
            if (isset($array['capacity'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Total Capacity (TB)',
                    'sensor_index' => 'array_capacity_total',
                    'sensor_current' => $this->bytesToTB($array['capacity']),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }

            if (isset($array['space'])) {
                $space = $array['space'];

                // Total provisioned capacity
                if (isset($space['total_provisioned'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Total Provisioned (TB)',
                        'sensor_index' => 'array_total_provisioned',
                        'sensor_current' => $this->bytesToTB($space['total_provisioned']),
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Data reduction ratio
                if (isset($space['data_reduction'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Data Reduction (X:1)',
                        'sensor_index' => 'array_data_reduction',
                        'sensor_current' => round($space['data_reduction'], 2),
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Space usage percentage
                if (isset($space['total_physical'], $array['capacity']) && $array['capacity'] > 0) {
                    $usedPercent = ($space['total_physical'] / $array['capacity']) * 100;
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Space Used',
                        'sensor_index' => 'array_space_used_pct',
                        'sensor_current' => round($usedPercent, 2),
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Extract performance-related sensors (IOPS, bandwidth, latency)
     */
    private function extractPerformanceSensors(array $perfPayload): array
    {
        $sensors = [];

        if (!isset($perfPayload['items']) || !is_array($perfPayload['items'])) {
            return $sensors;
        }

        foreach ($perfPayload['items'] as $perf) {
            $arrayName = $perf['name'] ?? 'array';

            // Read IOPS
            if (isset($perf['reads_per_sec'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Read IOPS',
                    'sensor_index' => 'array_read_iops',
                    'sensor_current' => $perf['reads_per_sec'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Write IOPS
            if (isset($perf['writes_per_sec'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Write IOPS',
                    'sensor_index' => 'array_write_iops',
                    'sensor_current' => $perf['writes_per_sec'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Read bandwidth
            if (isset($perf['read_bytes_per_sec'])) {
                $sensors[] = [
                    'sensor_class' => 'rate',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Read Bandwidth',
                    'sensor_index' => 'array_read_bw',
                    'sensor_current' => $perf['read_bytes_per_sec'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Write bandwidth
            if (isset($perf['write_bytes_per_sec'])) {
                $sensors[] = [
                    'sensor_class' => 'rate',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Write Bandwidth',
                    'sensor_index' => 'array_write_bw',
                    'sensor_current' => $perf['write_bytes_per_sec'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Read latency
            if (isset($perf['usec_per_read_op'])) {
                $sensors[] = [
                    'sensor_class' => 'delay',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Read Latency',
                    'sensor_index' => 'array_read_latency',
                    'sensor_current' => $perf['usec_per_read_op'],
                    'sensor_limit' => 10000,
                    'sensor_limit_low' => 0,
                ];
            }

            // Write latency
            if (isset($perf['usec_per_write_op'])) {
                $sensors[] = [
                    'sensor_class' => 'delay',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $arrayName . ' Write Latency',
                    'sensor_index' => 'array_write_latency',
                    'sensor_current' => $perf['usec_per_write_op'],
                    'sensor_limit' => 10000,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    /**
     * Extract storage data
     */
    private function extractStorage(array $arrayPayload): array
    {
        $storage = [];

        if (!isset($arrayPayload['items']) || !is_array($arrayPayload['items'])) {
            return $storage;
        }

        foreach ($arrayPayload['items'] as $array) {
            if (!isset($array['capacity'], $array['space'])) {
                continue;
            }

            $arrayName = $array['name'] ?? 'array';
            $totalCapacity = $array['capacity'];
            $totalPhysical = $array['space']['total_physical'] ?? 0;
            $free = max($totalCapacity - $totalPhysical, 0);

            $storage[] = [
                'storage_index' => 'array_' . ($array['id'] ?? '0'),
                'storage_descr' => $arrayName . ' Capacity',
                'storage_type' => 'flasharray',
                'storage_size' => $totalCapacity,
                'storage_used' => $totalPhysical,
                'storage_free' => $free,
                'storage_units' => 1,
                'storage_perc' => $totalCapacity > 0 ? round(($totalPhysical / $totalCapacity) * 100, 2) : 0,
            ];
        }

        return $storage;
    }

    /**
     * Extract mempool data from queue depth
     */
    private function extractMempools(array $perfPayload): array
    {
        $mempools = [];

        if (!isset($perfPayload['items']) || !is_array($perfPayload['items'])) {
            return $mempools;
        }

        foreach ($perfPayload['items'] as $perf) {
            if (!isset($perf['queue_depth'])) {
                continue;
            }

            $arrayName = $perf['name'] ?? 'array';
            $queueDepth = $perf['queue_depth'];
            $maxQueue = 1000; // Assumed maximum queue depth
            $usedPerc = min(($queueDepth / $maxQueue) * 100, 100);

            $mempools[] = [
                'mempool_index' => 'array_queue_' . substr(md5($arrayName), 0, 8),
                'mempool_descr' => $arrayName . ' Queue Depth',
                'mempool_type' => 'purestorage',
                'mempool_class' => 'system',
                'mempool_used' => $queueDepth,
                'mempool_free' => max($maxQueue - $queueDepth, 0),
                'mempool_total' => $maxQueue,
                'mempool_perc' => round($usedPerc, 2),
            ];
        }

        return $mempools;
    }
}
