<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - NodesToSensors Normalizer
 *
 * Capability: sensors
 * Vendor: isilon
 */
class NodesToSensors extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'isilon';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $list = $payload['nodes'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = $this->stableIndexFromName($name);
            $state = strtolower((string)($node['state'] ?? 'unknown'));
            $map = ['up' => 2, 'down' => 0, 'unknown' => 3];

            $sensors[] = [
                'sensor_class'   => 'state',
                'sensor_type'    => 'isilon',
                'sensor_descr'   => "Node $name State",
                'sensor_index'   => "isilon_node_state_$index",
                'sensor_current' => $map[$state] ?? 3,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'down'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'up'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];

            if (isset($node['uptime'])) {
                $sensors[] = [
                    'sensor_class'   => 'runtime',
                    'sensor_type'    => 'isilon',
                    'sensor_descr'   => "Node $name Uptime",
                    'sensor_index'   => "isilon_node_uptime_$index",
                    'sensor_current' => (int)$node['uptime'],
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }
}
