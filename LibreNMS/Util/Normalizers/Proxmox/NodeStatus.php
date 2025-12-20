<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - NodeStatus Normalizer
 *
 * Capability: sensors
 * Vendor: proxmox
 */
class NodeStatus extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $processors = [];
        $mempools = [];

        if (!isset($payload['data'])) {
            return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
        }

        $data = $payload['data'];

        // CPU usage
        if (isset($data['cpu'])) {
            $cpuPercent = $data['cpu'] * 100;
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'CPU Usage',
                'sensor_index' => 'node_cpu',
                'sensor_current' => round($cpuPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'proxmox-cpu',
                'processor_descr' => 'Node CPU',
                'processor_usage' => round($cpuPercent, 2),
            ];
        }

        // Memory usage
        if (isset($data['memory']) && isset($data['memory']['used']) && isset($data['memory']['total'])) {
            $memUsed = $data['memory']['used'];
            $memTotal = $data['memory']['total'];
            $memPercent = ($memTotal > 0) ? ($memUsed / $memTotal) * 100 : 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'node_mem',
                'sensor_current' => round($memPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $mempools[] = [
                'mempool_index' => 0,
                'mempool_type' => 'proxmox',
                'mempool_descr' => 'Node Memory',
                'mempool_total' => $memTotal,
                'mempool_used' => $memUsed,
                'mempool_free' => $memTotal - $memUsed,
                'mempool_perc' => round($memPercent, 2),
            ];
        }

        // Swap usage
        if (isset($data['swap']) && isset($data['swap']['used']) && isset($data['swap']['total'])) {
            $swapUsed = $data['swap']['used'];
            $swapTotal = $data['swap']['total'];
            $swapPercent = ($swapTotal > 0) ? ($swapUsed / $swapTotal) * 100 : 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Swap Usage',
                'sensor_index' => 'node_swap',
                'sensor_current' => round($swapPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $mempools[] = [
                'mempool_index' => 1,
                'mempool_type' => 'proxmox-swap',
                'mempool_descr' => 'Node Swap',
                'mempool_total' => $swapTotal,
                'mempool_used' => $swapUsed,
                'mempool_free' => $swapTotal - $swapUsed,
                'mempool_perc' => round($swapPercent, 2),
            ];
        }

        // Uptime
        if (isset($data['uptime'])) {
            $sensors[] = [
                'sensor_class' => 'runtime',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Node Uptime',
                'sensor_index' => 'node_uptime',
                'sensor_current' => $data['uptime'],
                'sensor_limit' => null,
                'sensor_limit_low' => 0,
            ];
        }

        // Load average
        if (isset($data['loadavg']) && is_array($data['loadavg'])) {
            if (isset($data['loadavg'][0])) {
                $sensors[] = [
                    'sensor_class' => 'load',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Load Average (1min)',
                    'sensor_index' => 'node_load1',
                    'sensor_current' => $data['loadavg'][0],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
    }
}
