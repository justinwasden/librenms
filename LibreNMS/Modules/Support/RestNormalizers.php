<?php

namespace LibreNMS\Modules\Support;

class RestNormalizers
{
    // Existing Pure normalizers (as provided)
    public static function normalizePureArraySensors(array $arrayPayload, array $perfPayload = []): array
    {
        $sensors = [];

        // Array info from /arrays endpoint
        if (isset($arrayPayload['items']) && is_array($arrayPayload['items'])) {
            foreach ($arrayPayload['items'] as $array) {
                $arrayName = $array['name'] ?? 'array';

                // Capacity sensors
                if (isset($array['capacity'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Total Capacity',
                        'sensor_index' => 'array_capacity_total',
                        'sensor_current' => $array['capacity'] ?? 0,
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                    ];
                }

                if (isset($array['space'])) {
                    $space = $array['space'];

                    // Data reduction ratio
                    if (isset($space['data_reduction'])) {
                        $sensors[] = [
                            'sensor_class' => 'count',
                            'sensor_type' => 'purestorage',
                            'sensor_descr' => $arrayName . ' Data Reduction Ratio',
                            'sensor_index' => 'array_data_reduction',
                            'sensor_current' => $space['data_reduction'],
                            'sensor_limit' => null,
                            'sensor_limit_low' => 1,
                        ];
                    }

                    // Space usage percentage
                    if (isset($space['total_physical']) && $space['total_physical'] > 0) {
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
        }

        // Performance metrics from /arrays/performance endpoint
        if (isset($perfPayload['items']) && is_array($perfPayload['items'])) {
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

                // Read bandwidth (bytes/sec)
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

                // Write bandwidth (bytes/sec)
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

                // Latency (microseconds)
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
        }

        return $sensors;
    }
    public static function normalizePureHardware(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';
            $type = $hw['type'] ?? 'unknown';
            $status = $hw['status'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Inventory entry
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => $name,
                'entPhysicalClass' => self::mapPureHardwareType($type),
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $hw['model'] ?? '',
                'entPhysicalSerialNum' => $hw['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => $hw['slot'] ?? -1,
                'entPhysicalVendorType' => $type,
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $hw['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // State sensor for component health
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'purestorage',
                'sensor_descr' => $name . ' Status',
                'sensor_index' => 'hw_' . $index,
                'sensor_current' => self::pureStatusToNumeric($status),
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'critical'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'healthy'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];

            // Temperature sensors
            if (isset($hw['temperature']) && is_numeric($hw['temperature'])) {
                $sensors[] = [
                    'sensor_class' => 'temperature',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Temperature',
                    'sensor_index' => 'hw_temp_' . $index,
                    'sensor_current' => $hw['temperature'],
                    'sensor_limit' => 85,
                    'sensor_limit_low' => 0,
                ];
            }

            // Voltage sensors (for PSUs)
            if ($type === 'psu' && isset($hw['voltage']) && is_numeric($hw['voltage'])) {
                $sensors[] = [
                    'sensor_class' => 'voltage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Voltage',
                    'sensor_index' => 'hw_volt_' . $index,
                    'sensor_current' => $hw['voltage'],
                    'sensor_limit' => 13,
                    'sensor_limit_low' => 11,
                ];
            }

            // Fan speed (RPM)
            if ($type === 'fan' && isset($hw['speed']) && is_numeric($hw['speed'])) {
                $sensors[] = [
                    'sensor_class' => 'fanspeed',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Speed',
                    'sensor_index' => 'hw_fan_' . $index,
                    'sensor_current' => $hw['speed'],
                    'sensor_limit' => 20000,
                    'sensor_limit_low' => 1000,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
    public static function normalizePureNetworkInterfaces(array $payload): array
    {
        $ports = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $ports;
        }

        foreach ($payload['items'] as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";
            $enabled = ($iface['enabled'] ?? false) ? 'up' : 'down';
            $speed = $iface['speed'] ?? 0;

            // Pure Storage appears to return speed already in bits per second
            // Cap at max BIGINT value to avoid database overflow (use 2^63-1 as safe limit)
            $speedBps = min($speed, 9223372036854775807);

            $ports[] = [
                'ifIndex' => self::stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $speedBps,
                'ifOperStatus' => $enabled,
                'ifAdminStatus' => $enabled,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['hwaddr'] ?? '',
                'ifAlias' => $iface['description'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }
    public static function normalizePureNetworkPerformance(array $payload, int $pollIntervalSec): array
    {
        $stats = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $stats;
        }

        foreach ($payload['items'] as $perf) {
            $name = $perf['name'] ?? '';
            $ifIndex = self::stableIndexFromName($name);

            // Convert bytes/sec to counter (multiply by poll interval)
            $rxBytes = ($perf['received_bytes_per_sec'] ?? 0) * $pollIntervalSec;
            $txBytes = ($perf['transmitted_bytes_per_sec'] ?? 0) * $pollIntervalSec;

            $stats[$ifIndex] = [
                'ifInOctets' => $rxBytes,
                'ifOutOctets' => $txBytes,
                'ifInErrors' => $perf['received_errors_per_sec'] ?? 0,
                'ifOutErrors' => $perf['transmitted_errors_per_sec'] ?? 0,
                'ifInUcastPkts' => $perf['received_packets_per_sec'] ?? 0,
                'ifOutUcastPkts' => $perf['transmitted_packets_per_sec'] ?? 0,
            ];
        }

        return $stats;
    }
    public static function normalizePurePortOptics(array $payload): array
    {
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $port) {
            $name = $port['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Optical power sensors (dBm)
            if (isset($port['wwn'])) {
                if (isset($port['rx_power']) && is_numeric($port['rx_power'])) {
                    $sensors[] = [
                        'sensor_class' => 'dbm',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $name . ' RX Power',
                        'sensor_index' => 'port_rx_' . $index,
                        'sensor_current' => $port['rx_power'],
                        'sensor_limit' => 0,
                        'sensor_limit_low' => -20,
                    ];
                }

                if (isset($port['tx_power']) && is_numeric($port['tx_power'])) {
                    $sensors[] = [
                        'sensor_class' => 'dbm',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $name . ' TX Power',
                        'sensor_index' => 'port_tx_' . $index,
                        'sensor_current' => $port['tx_power'],
                        'sensor_limit' => 2,
                        'sensor_limit_low' => -10,
                    ];
                }
            }
        }

        return $sensors;
    }
    public static function normalizePureVolumes(array $volumesPayload, array $volPerfPayload = []): array
    {
        $sensors = [];

        if (!isset($volumesPayload['items']) || !is_array($volumesPayload['items'])) {
            return $sensors;
        }

        // Index performance data by volume name
        $perfByName = [];
        if (isset($volPerfPayload['items']) && is_array($volPerfPayload['items'])) {
            foreach ($volPerfPayload['items'] as $perf) {
                $volName = $perf['name'] ?? '';
                if ($volName) {
                    $perfByName[$volName] = $perf;
                }
            }
        }

        foreach ($volumesPayload['items'] as $vol) {
            $name = $vol['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Volume size
            if (isset($vol['provisioned'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => 'Vol ' . $name . ' Provisioned',
                    'sensor_index' => 'vol_prov_' . $index,
                    'sensor_current' => $vol['provisioned'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Add performance metrics if available
            if (isset($perfByName[$name])) {
                $perf = $perfByName[$name];

                // Volume IOPS
                if (isset($perf['reads_per_sec']) && isset($perf['writes_per_sec'])) {
                    $totalIops = $perf['reads_per_sec'] + $perf['writes_per_sec'];
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => 'Vol ' . $name . ' IOPS',
                        'sensor_index' => 'vol_iops_' . $index,
                        'sensor_current' => $totalIops,
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Volume latency
                if (isset($perf['usec_per_read_op']) && isset($perf['usec_per_write_op'])) {
                    $avgLatency = ($perf['usec_per_read_op'] + $perf['usec_per_write_op']) / 2;
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => 'Vol ' . $name . ' Latency',
                        'sensor_index' => 'vol_latency_' . $index,
                        'sensor_current' => $avgLatency,
                        'sensor_limit' => 10000,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }
    public static function normalizePureHosts(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['items'] as $host) {
            $name = $host['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Inventory for connected hosts
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Host: ' . $name,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $host['personality'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => '',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'host',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Connection state sensor
            $isConnected = ($host['is_local'] ?? false) ? 2 : 0;
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'purestorage',
                'sensor_descr' => 'Host ' . $name . ' Connection',
                'sensor_index' => 'host_conn_' . $index,
                'sensor_current' => $isConnected,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'disconnected'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'partial'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'connected'],
                ],
            ];
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
    public static function normalizeProxmoxNodeStatus(array $payload): array
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
    public static function normalizeProxmoxNodeNetwork(array $payload): array
    {
        $ports = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $ports;
        }

        foreach ($payload['data'] as $idx => $iface) {
            $name = $iface['iface'] ?? "iface_$idx";
            $active = ($iface['active'] ?? 0) ? 'up' : 'down';

            $ports[] = [
                'ifIndex' => self::stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['comments'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => 1000000000, // Default to 1Gbps
                'ifOperStatus' => $active,
                'ifAdminStatus' => ($iface['autostart'] ?? 1) ? 'up' : 'down',
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['address'] ?? '',
                'ifAlias' => $iface['comments'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }
    public static function normalizeProxmoxNodeStorage(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['data'] as $storage) {
            $name = $storage['storage'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Storage inventory
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Storage: ' . $name,
                'entPhysicalClass' => 'container',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $storage['type'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Proxmox',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'storage',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Storage usage
            if (isset($storage['used']) && isset($storage['total']) && $storage['total'] > 0) {
                $usedPercent = ($storage['used'] / $storage['total']) * 100;
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Storage ' . $name . ' Usage',
                    'sensor_index' => 'storage_' . $index,
                    'sensor_current' => round($usedPercent, 2),
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            // Storage capacity
            if (isset($storage['total'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Storage ' . $name . ' Total',
                    'sensor_index' => 'storage_total_' . $index,
                    'sensor_current' => $storage['total'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
    public static function normalizeProxmoxClusterStatus(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['data'] as $item) {
            $type = $item['type'] ?? 'unknown';
            $name = $item['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            if ($type === 'node') {
                // Node inventory
                $inventory[] = [
                    'entPhysicalIndex' => $index,
                    'entPhysicalDescr' => 'Node: ' . $name,
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => '',
                    'entPhysicalSerialNum' => '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'Proxmox',
                    'entPhysicalParentRelPos' => $item['nodeid'] ?? -1,
                    'entPhysicalVendorType' => 'node',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => 0,
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];

                // Node online state
                $isOnline = ($item['online'] ?? 0) ? 2 : 0;
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Node ' . $name . ' Status',
                    'sensor_index' => 'node_online_' . $index,
                    'sensor_current' => $isOnline,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'offline'],
                        ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                        ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'online'],
                    ],
                ];
            } elseif ($type === 'cluster') {
                // Cluster inventory
                $inventory[] = [
                    'entPhysicalIndex' => $index,
                    'entPhysicalDescr' => 'Cluster: ' . $name,
                    'entPhysicalClass' => 'stack',
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => 'Proxmox VE Cluster',
                    'entPhysicalSerialNum' => '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'Proxmox',
                    'entPhysicalParentRelPos' => -1,
                    'entPhysicalVendorType' => 'cluster',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => 0,
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];

                // Quorum state
                if (isset($item['quorate'])) {
                    $isQuorate = $item['quorate'] ? 2 : 0;
                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'proxmox',
                        'sensor_descr' => 'Cluster Quorum',
                        'sensor_index' => 'cluster_quorum',
                        'sensor_current' => $isQuorate,
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'no-quorum'],
                            ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'quorate'],
                        ],
                    ];
                }

                // Node count
                if (isset($item['nodes'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'proxmox',
                        'sensor_descr' => 'Cluster Nodes',
                        'sensor_index' => 'cluster_nodes',
                        'sensor_current' => $item['nodes'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 1,
                    ];
                }
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
    public static function normalizeProxmoxClusterResources(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        // Count VMs and containers
        $vmCount = 0;
        $ctCount = 0;
        $runningVms = 0;
        $runningCts = 0;

        foreach ($payload['data'] as $resource) {
            $type = $resource['type'] ?? '';
            $status = $resource['status'] ?? '';

            if ($type === 'qemu') {
                $vmCount++;
                if ($status === 'running') {
                    $runningVms++;
                }
            } elseif ($type === 'lxc') {
                $ctCount++;
                if ($status === 'running') {
                    $runningCts++;
                }
            }
        }

        // VM count sensors
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Total VMs',
            'sensor_index' => 'resource_vm_total',
            'sensor_current' => $vmCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Running VMs',
            'sensor_index' => 'resource_vm_running',
            'sensor_current' => $runningVms,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        // Container count sensors
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Total Containers',
            'sensor_index' => 'resource_ct_total',
            'sensor_current' => $ctCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Running Containers',
            'sensor_index' => 'resource_ct_running',
            'sensor_current' => $runningCts,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

		public static function normalizeFortigateSystemUsage(array $payload): array
    {
        $sensors = [];
        $processors = [];
        $mempools = [];

        $results = $payload['results'] ?? $payload;

        // CPU usage
        if (isset($results['cpu'])) {
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'fortigate',
                'sensor_descr' => 'CPU Usage',
                'sensor_index' => 'cpu_usage',
                'sensor_current' => $results['cpu'],
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'fortigate-cpu',
                'processor_descr' => 'System CPU',
                'processor_usage' => $results['cpu'],
            ];
        }

        // Memory usage
        if (isset($results['mem'])) {
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'fortigate',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'mem_usage',
                'sensor_current' => $results['mem'],
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            // Approximate total/used based on percentage (FortiGate doesn't always provide absolute values)
            $memTotal = 100; // placeholder
            $memUsed = $results['mem'];
            $mempools[] = [
                'mempool_index' => 0,
                'mempool_type' => 'fortigate',
                'mempool_descr' => 'System Memory',
                'mempool_total' => $memTotal,
                'mempool_used' => $memUsed,
                'mempool_free' => $memTotal - $memUsed,
                'mempool_perc' => $results['mem'],
            ];
        }

        return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
    }

    public static function normalizeFortigateSystemStatus(array $payload): array
    {
        $inventory = [];
        $sensors = [];

        $results = $payload['results'] ?? $payload;

        // System inventory
        if (isset($results['serial'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => ($results['hostname'] ?? 'FortiGate') . ' Chassis',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $results['hostname'] ?? 'FortiGate',
                'entPhysicalModelName' => $results['model'] ?? '',
                'entPhysicalSerialNum' => $results['serial'],
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Fortinet',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'fortigate',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $results['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    public static function normalizeFortigateInterfaces(array $payload): array
    {
        $ports = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return $ports;
        }

        foreach ($results as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";
            $status = strtolower($iface['status'] ?? 'down');

            $ports[] = [
                'ifIndex' => self::stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['alias'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => ($iface['speed'] ?? 1000) * 1000000, // Mbps to bps
                'ifOperStatus' => $status === 'up' ? 'up' : 'down',
                'ifAdminStatus' => $status === 'up' ? 'up' : 'down',
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['macaddr'] ?? '',
                'ifAlias' => $iface['alias'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }

    public static function normalizeFortigateIpv4(array $payload): array
    {
        $addresses = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return $addresses;
        }

        foreach ($results as $iface) {
            $ifName = $iface['name'] ?? '';
            $ip = $iface['ip'] ?? $iface['ipv4'] ?? '';

            if (!$ip || $ip === '0.0.0.0') {
                continue;
            }

            // Parse IP/CIDR
            if (strpos($ip, '/') !== false) {
                [$ipAddr, $prefixLen] = explode('/', $ip, 2);
            } else {
                $ipAddr = $ip;
                $prefixLen = $iface['netmask'] ? self::netmaskToCidr($iface['netmask']) : 24;
            }

            $addresses[] = [
                'ifIndex' => self::stableIndexFromName($ifName),
                'ipv4_address' => $ipAddr,
                'ipv4_prefixlen' => $prefixLen,
                'context_name' => '',
            ];
        }

        return $addresses;
    }

    public static function normalizeJunosInterfaces(array $payload): array { return []; }
    public static function normalizeJunosInventory(array $payload): array { return []; }
    public static function normalizeJunosSystem(array $payload): array { return []; }

    public static function normalizeDellSystem(array $payload): array { return []; }
    public static function normalizeDellInterfaces(array $payload): array { return []; }
    public static function normalizeDellSensors(array $payload): array { return []; }

    public static function normalizeHpeSystem(array $payload): array { return []; }
    public static function normalizeHpeInterfaces(array $payload): array { return []; }
    public static function normalizeHpeSensors(array $payload): array { return []; }

    public static function normalizeNimbleArrays(array $payload): array { return []; }
    public static function normalizeNimbleDisks(array $payload): array { return []; }
    public static function normalizeNimbleStats(array $payload): array { return []; }
    public static function normalizeNimbleInterfaces(array $payload): array { return []; }

    public static function normalizeNutanixClusters(array $payload): array { return []; }
    public static function normalizeNutanixHosts(array $payload): array { return []; }
    public static function normalizeNutanixStorage(array $payload): array { return []; }

    public static function normalizeIseNetworkDevices(array $payload): array { return []; }
    public static function normalizeIseEndpoints(array $payload): array { return []; }

    public static function normalizeEsxiVersion(array $payload): array
    {
        $inventory = [];

        $value = $payload['value'] ?? $payload;

        if (isset($value['version'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'ESXi Host',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'ESXi',
                'entPhysicalModelName' => $value['product'] ?? 'ESXi',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'VMware',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'esxi',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $value['version'] ?? '',
                'entPhysicalSoftwareRev' => $value['build'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeEsxiHealth(array $payload): array
    {
        $sensors = [];

        $value = $payload['value'] ?? $payload;

        // Overall system health
        if (isset($value['system_health'])) {
            $healthMap = ['green' => 2, 'yellow' => 1, 'orange' => 1, 'red' => 0, 'gray' => 3];
            $health = strtolower($value['system_health']);
            $healthValue = $healthMap[$health] ?? 3;

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'esxi',
                'sensor_descr' => 'System Health',
                'sensor_index' => 'system_health',
                'sensor_current' => $healthValue,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'red'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'yellow/orange'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'green'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'gray/unknown'],
                ],
            ];
        }

        return $sensors;
    }

    public static function normalizePanInventory(array $payload): array { return []; }
    public static function normalizePanInterfaces(array $payload): array { return []; }
    public static function normalizePanSystem(array $payload): array { return []; }

    public static function normalizeNxInterfaces(array $payload): array { return []; }
    public static function normalizeNxInventory(array $payload): array { return []; }

    public static function normalizeIosxrInterfaces(array $payload): array { return []; }
    public static function normalizeIosxrInventory(array $payload): array { return []; }

    public static function normalizeCucmInventory(array $payload): array { return []; }

    public static function normalizeCalixDevices(array $payload): array { return []; }
    public static function normalizeCalixInterfaces(array $payload): array { return []; }
    public static function normalizeCalixSensors(array $payload): array { return []; }

    public static function normalizeNdfcDevices(array $payload): array { return []; }
    public static function normalizeNdfcInterfaces(array $payload): array { return []; }

    public static function normalizeAristaSystem(array $payload): array { return []; }
    public static function normalizeAristaInterfaces(array $payload): array { return []; }
    public static function normalizeAristaSensors(array $payload): array { return []; }

    public static function normalizeExtremeSystem(array $payload): array { return []; }
    public static function normalizeExtremeInterfaces(array $payload): array { return []; }
    public static function normalizeExtremeSensors(array $payload): array { return []; }

    public static function normalizeBrocadeSystem(array $payload): array { return []; }
    public static function normalizeBrocadeInterfaces(array $payload): array { return []; }

    public static function normalizeSonicSystem(array $payload): array { return []; }
    public static function normalizeSonicInterfaces(array $payload): array { return []; }
    public static function normalizeSonicSensors(array $payload): array { return []; }

    public static function normalizeCheckpointGateways(array $payload): array { return []; }
    public static function normalizeCheckpointInterfaces(array $payload): array { return []; }

    // NetApp ONTAP
    public static function normalizeOntapEthernetPorts(array $payload): array
    {
        $ports = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $idx => $p) {
            $name = $p['name'] ?? ("port_$idx");
            $ifIndex = self::stableIndexFromName($name);
            $ports[] = [
                'ifIndex'       => $ifIndex,
                'ifName'        => $name,
                'ifDescr'       => $p['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($p['speed'] ?? 0),
                'ifOperStatus'  => self::toStatus($p['enabled'] ?? true),
                'ifAdminStatus' => self::toStatus($p['enabled'] ?? true),
                'ifMtu'         => (int)($p['mtu'] ?? 1500),
                'ifPhysAddress' => $p['mac'] ?? '',
                'ifAlias'       => $p['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }

    public static function normalizeOntapVolumesToStorage(array $payload): array
    {
        $sensors = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);
            $size = (int)($vol['space']['size'] ?? $vol['size'] ?? 0);
            $used = (int)($vol['space']['used'] ?? $vol['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Volume $name Used",
                    'sensor_index'   => "ontap_vol_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
                $sensors[] = [
                    'sensor_class'   => 'storage',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Volume $name Size",
                    'sensor_index'   => "ontap_vol_size_$index",
                    'sensor_current' => $size,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizeOntapAggregatesToSensors(array $payload): array
    {
        $sensors = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $aggr) {
            $name = $aggr['name'] ?? 'aggregate';
            $index = self::stableIndexFromName($name);
            $size = (int)($aggr['space']['size'] ?? 0);
            $used = (int)($aggr['space']['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Aggregate $name Used",
                    'sensor_index'   => "ontap_aggr_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            $state = strtolower((string)($aggr['state'] ?? 'unknown'));
            $map = ['online' => 2, 'relocating' => 1, 'offline' => 0, 'unknown' => 3];
            $sensors[] = [
                'sensor_class'   => 'state',
                'sensor_type'    => 'netapp',
                'sensor_descr'   => "Aggregate $name State",
                'sensor_index'   => "ontap_aggr_state_$index",
                'sensor_current' => $map[$state] ?? 3,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'offline'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'relocating'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'online'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];
        }

        return $sensors;
    }

    public static function normalizeOntapNodesToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "ONTAP Node: $name",
                'entPhysicalClass'        => 'chassis',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $node['model'] ?? '',
                'entPhysicalSerialNum'    => $node['serial_number'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'NetApp',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'node',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $node['version'] ?? '',
                'entPhysicalSoftwareRev'  => $node['version'] ?? '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeOntapDisksToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $disk) {
            $name = $disk['name'] ?? 'disk';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Disk: $name",
                'entPhysicalClass'        => 'diskDrive',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $disk['model'] ?? '',
                'entPhysicalSerialNum'    => $disk['serial_number'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'NetApp',
                'entPhysicalParentRelPos' => (int)($disk['bay'] ?? -1),
                'entPhysicalVendorType'   => 'disk',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $disk['firmware'] ?? '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 1,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeOntapNodeMetricsToProcessorsMempools(array $payload): array
    {
        $processors = [];
        $mempools = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = self::stableIndexFromName($name);

            $cpuPct = null;
            if (isset($node['cpu_utilization']['percent'])) {
                $cpuPct = (float)$node['cpu_utilization']['percent'];
            } elseif (isset($node['cpu']['percent'])) {
                $cpuPct = (float)$node['cpu']['percent'];
            } elseif (isset($node['cpu'])) {
                $cpu = $node['cpu'];
                $cpuPct = is_array($cpu) && isset($cpu['overall']) ? (float)$cpu['overall'] : (is_numeric($cpu) ? (float)$cpu : null);
            }

            if ($cpuPct !== null) {
                $processors[] = [
                    'processor_index' => $index,
                    'processor_type' => 'netapp-cpu',
                    'processor_descr' => "Node $name CPU",
                    'processor_usage' => round($cpuPct, 2),
                ];
            }

            $memTotal = null;
            $memUsed = null;
            if (isset($node['memory']['total'])) {
                $memTotal = (int)$node['memory']['total'];
                $memUsed  = (int)($node['memory']['used'] ?? 0);
            } elseif (isset($node['memory_total'])) {
                $memTotal = (int)$node['memory_total'];
                $memUsed  = (int)($node['memory_used'] ?? 0);
            }

            if ($memTotal && $memTotal > 0) {
                $mempools[] = [
                    'mempool_index' => $index,
                    'mempool_type' => 'netapp',
                    'mempool_descr' => "Node $name Memory",
                    'mempool_used' => $memUsed ?? 0,
                    'mempool_free' => $memTotal - ($memUsed ?? 0),
                    'mempool_total' => $memTotal,
                    'mempool_perc' => round(($memUsed ?? 0) / $memTotal * 100, 2),
                ];
            }
        }

        return ['processors' => $processors, 'mempools' => $mempools];
    }

    // Unity
    public static function normalizeUnityPoolsToStorage(array $payload): array
    {
        $sensors = [];
        $entries = $payload['entries'] ?? $payload['items'] ?? $payload['records'] ?? [];

        foreach ($entries as $entry) {
            $pool = $entry['content'] ?? $entry;
            $name = $pool['name'] ?? ($pool['id'] ?? 'pool');
            $index = self::stableIndexFromName($name);
            $total = (int)($pool['sizeTotal'] ?? 0);
            $used  = (int)($pool['sizeUsed'] ?? 0);

            if ($total > 0) {
                $pct = ($used / $total) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'unity',
                    'sensor_descr'   => "Pool $name Used",
                    'sensor_index'   => "unity_pool_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizeUnityResourcesToSensors(array $payload): array
    {
        $sensors = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $res = $entry['content'] ?? $entry;
            $name = $res['name'] ?? 'resource';
            $index = self::stableIndexFromName($name);
            $total = (int)($res['sizeTotal'] ?? 0);
            $used  = (int)($res['sizeUsed'] ?? 0);

            if ($total > 0) {
                $pct = ($used / $total) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'unity',
                    'sensor_descr'   => "Resource $name Used",
                    'sensor_index'   => "unity_res_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizeUnityResourcesToInventory(array $payload): array
    {
        $inventory = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $res = $entry['content'] ?? $entry;
            $name = $res['name'] ?? ($res['id'] ?? 'resource');
            $index = self::stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Unity Resource: $name",
                'entPhysicalClass'        => 'other',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $res['type'] ?? '',
                'entPhysicalSerialNum'    => $res['id'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'storageResource',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeUnityDisksToInventory(array $payload): array
    {
        $inventory = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $d = $entry['content'] ?? $entry;
            $name = $d['name'] ?? ($d['id'] ?? 'disk');
            $index = self::stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Unity Disk: $name",
                'entPhysicalClass'        => 'diskDrive',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $d['model'] ?? '',
                'entPhysicalSerialNum'    => $d['emcSerialNumber'] ?? ($d['serialNumber'] ?? ''),
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => (int)($d['slotNumber'] ?? -1),
                'entPhysicalVendorType'   => 'disk',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $d['firmwareRevision'] ?? '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 1,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeUnityEthPortsToPorts(array $payload): array
    {
        $ports = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $p = $entry['content'] ?? $entry;
            $name = $p['name'] ?? ($p['id'] ?? 'ethPort');
            $index = self::stableIndexFromName($name);

            $ports[] = [
                'ifIndex'       => $index,
                'ifName'        => $name,
                'ifDescr'       => $p['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($p['speed'] ?? 1000000000),
                'ifOperStatus'  => self::toStatus($p['linkStatus'] ?? ($p['enabled'] ?? true)),
                'ifAdminStatus' => self::toStatus($p['enabled'] ?? true),
                'ifMtu'         => (int)($p['mtu'] ?? 1500),
                'ifPhysAddress' => $p['macAddress'] ?? '',
                'ifAlias'       => $p['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }

    // Isilon / PowerScale
    public static function normalizeIsilonInterfacesToPorts(array $payload): array
    {
        $ports = [];
        $list = $payload['interfaces'] ?? $payload['items'] ?? [];

        foreach ($list as $idx => $iface) {
            $name = $iface['name'] ?? ("iface_$idx");
            $index = self::stableIndexFromName($name);

            $ports[] = [
                'ifIndex'       => $index,
                'ifName'        => $name,
                'ifDescr'       => $iface['description'] ?? $name,
                'ifType'        => 'ethernetCsmacd',
                'ifSpeed'       => (int)($iface['speed'] ?? 1000000000),
                'ifOperStatus'  => self::toStatus($iface['status'] ?? 'up'),
                'ifAdminStatus' => self::toStatus($iface['enabled'] ?? true),
                'ifMtu'         => (int)($iface['mtu'] ?? 1500),
                'ifPhysAddress' => $iface['mac'] ?? '',
                'ifAlias'       => $iface['description'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }

    public static function normalizeIsilonPoolsToStorage(array $payload): array
    {
        $sensors = [];
        $list = $payload['pools'] ?? $payload['items'] ?? [];

        foreach ($list as $pool) {
            $name = $pool['name'] ?? 'pool';
            $index = self::stableIndexFromName($name);
            $size = (int)($pool['size'] ?? 0);
            $used = (int)($pool['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'isilon',
                    'sensor_descr'   => "Pool $name Used",
                    'sensor_index'   => "isilon_pool_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizeIsilonNodesToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['nodes'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Isilon Node: $name",
                'entPhysicalClass'        => 'chassis',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $node['model'] ?? '',
                'entPhysicalSerialNum'    => $node['serial_number'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'node',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $node['firmware'] ?? '',
                'entPhysicalSoftwareRev'  => $node['onefs_version'] ?? '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function normalizeIsilonNodesToSensors(array $payload): array
    {
        $sensors = [];
        $list = $payload['nodes'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = self::stableIndexFromName($name);
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

    public static function normalizeIsilonClusterStatusToSensors(array $payload): array
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

    // VMware vCenter
    public static function vcHostsToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['value'] ?? $payload;

        foreach ($list as $host) {
            $name = $host['name'] ?? $host['host'] ?? 'host';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "ESXi Host: $name",
                'entPhysicalClass'        => 'chassis',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $host['model'] ?? '',
                'entPhysicalSerialNum'    => $host['serial'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'VMware',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'host',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $host['version'] ?? '',
                'entPhysicalSoftwareRev'  => $host['version'] ?? '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function vcNetworksToPortsInventory(array $payload): array
    {
        $ports = [];
        $list = $payload['value'] ?? $payload;

        foreach ($list as $net) {
            $name = $net['name'] ?? 'network';
            $index = self::stableIndexFromName($name);
            $ports[] = [
                'ifIndex'       => $index,
                'ifName'        => $name,
                'ifDescr'       => "Network: $name",
                'ifType'        => 'other',
                'ifSpeed'       => 1000000000,
                'ifOperStatus'  => 'up',
                'ifAdminStatus' => 'up',
                'ifMtu'         => 1500,
                'ifPhysAddress' => '',
                'ifAlias'       => $net['type'] ?? '',
                'ifLastChange'  => 0,
            ];
        }

        return $ports;
    }

    public static function vcDatastoresToStorageSensors(array $payload): array
    {
        $sensors = [];
        $list = $payload['value'] ?? $payload;

        foreach ($list as $ds) {
            $name = $ds['name'] ?? 'datastore';
            $index = self::stableIndexFromName($name);
            $cap = (int)($ds['capacity'] ?? 0);
            $free = (int)($ds['freeSpace'] ?? 0);
            $used = $cap > 0 ? $cap - $free : 0;

            if ($cap > 0) {
                $pct = ($used / $cap) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'vmware',
                    'sensor_descr'   => "Datastore $name Used",
                    'sensor_index'   => "vcenter_ds_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function vcClustersToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['value'] ?? $payload;

        foreach ($list as $cluster) {
            $name = $cluster['name'] ?? 'cluster';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "vCenter Cluster: $name",
                'entPhysicalClass'        => 'container',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => '',
                'entPhysicalSerialNum'    => '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'VMware',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'cluster',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function vcHostSummaryToProcessorsMempools(array $payload): array
    {
        $processors = [];
        $mempools = [];
        $list = $payload['value'] ?? $payload;

        foreach ($list as $host) {
            $name = $host['name'] ?? $host['host'] ?? 'host';
            $index = self::stableIndexFromName($name);

            $cpuPct = null;
            if (isset($host['cpu']['usage_percent'])) {
                $cpuPct = (float)$host['cpu']['usage_percent'];
            } elseif (isset($host['cpu']['overall_usage']) && isset($host['cpu']['max_mhz'])) {
                $cpuPct = (float)$host['cpu']['overall_usage'] / max(1, (float)$host['cpu']['max_mhz']) * 100;
            }

            if ($cpuPct !== null) {
                $processors[] = [
                    'processor_index' => $index,
                    'processor_type' => 'vmware-cpu',
                    'processor_descr' => "Host $name CPU",
                    'processor_usage' => round($cpuPct, 2),
                ];
            }

            $memTotal = null;
            $memUsed = null;
            if (isset($host['memory']['total'])) {
                $memTotal = (int)$host['memory']['total'];
                $memUsed  = (int)($host['memory']['used'] ?? 0);
            } elseif (isset($host['memory']['effective'])) {
                $memTotal = (int)$host['memory']['effective'];
                $memUsed  = (int)($host['memory']['used'] ?? 0);
            }

            if ($memTotal && $memTotal > 0) {
                $mempools[] = [
                    'mempool_index' => $index,
                    'mempool_type' => 'vmware',
                    'mempool_descr' => "Host $name Memory",
                    'mempool_used' => $memUsed ?? 0,
                    'mempool_free' => $memTotal - ($memUsed ?? 0),
                    'mempool_total' => $memTotal,
                    'mempool_perc' => round(($memUsed ?? 0) / $memTotal * 100, 2),
                ];
            }
        }

        return ['processors' => $processors, 'mempools' => $mempools];
    }

    // Zabbix
    public static function zbHostGetToInventory(array $payload): array
    {
        $inventory = [];
        $list = $payload['result'] ?? [];

        foreach ($list as $host) {
            $name = $host['name'] ?? $host['host'] ?? 'host';
            $index = self::stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Zabbix Host: $name",
                'entPhysicalClass'        => 'other',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => '',
                'entPhysicalSerialNum'    => (string)($host['hostid'] ?? ''),
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => '',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'host',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }

    public static function zbHostInterfacesToPorts(array $payload): array
    {
        $ports = [];
        $list = $payload['result'] ?? [];

        foreach ($list as $host) {
            $hostName = $host['name'] ?? $host['host'] ?? 'host';
            $ifaces = $host['interfaces'] ?? [];

            foreach ($ifaces as $iface) {
                $name = $hostName . ':' . ($iface['name'] ?? $iface['ip'] ?? $iface['type'] ?? 'iface');
                $index = self::stableIndexFromName($name);
                $ports[] = [
                    'ifIndex'       => $index,
                    'ifName'        => $name,
                    'ifDescr'       => 'Zabbix Interface',
                    'ifType'        => 'other',
                    'ifSpeed'       => 1000000000,
                    'ifOperStatus'  => 'up',
                    'ifAdminStatus' => 'up',
                    'ifMtu'         => 1500,
                    'ifPhysAddress' => '',
                    'ifAlias'       => $iface['type'] ?? '',
                    'ifLastChange'  => 0,
                ];
            }
        }

        return $ports;
    }

    public static function zbItemGetToSensors(array $payload): array
    {
        $sensors = [];
        $items = $payload['result'] ?? [];

        foreach ($items as $item) {
            $name = $item['name'] ?? $item['key_'] ?? 'item';
            $index = self::stableIndexFromName($name);
            $key = $item['key_'] ?? '';
            $last = $item['lastvalue'] ?? null;
            $val = is_numeric($last) ? (float)$last : null;
            if ($val === null) {
                continue;
            }

            if (str_contains($key, 'system.cpu.util')) {
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => 'CPU Utilization',
                    'sensor_index'   => "zb_cpu_$index",
                    'sensor_current' => round($val, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            } elseif (str_contains($key, 'vm.memory.size[used]') || str_contains($key, 'memory.used')) {
                $sensors[] = [
                    'sensor_class'   => 'storage',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => 'Memory Used',
                    'sensor_index'   => "zb_mem_used_$index",
                    'sensor_current' => (int)$val,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                ];
            } elseif (str_contains($key, 'vfs.fs.size[/,used]')) {
                $sensors[] = [
                    'sensor_class'   => 'storage',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => 'Root FS Used',
                    'sensor_index'   => "zb_rootfs_used_$index",
                    'sensor_current' => (int)$val,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                ];
            } else {
                $sensors[] = [
                    'sensor_class'   => 'count',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => $name,
                    'sensor_index'   => "zb_item_$index",
                    'sensor_current' => $val,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    // Helpers for Pure Storage normalizers
    protected static function pureStatusToNumeric(string $status): int
    {
        return match (strtolower($status)) {
            'healthy', 'ok', 'normal' => 2,
            'degraded', 'warning' => 1,
            'critical', 'failed', 'unhealthy' => 0,
            default => 3, // unknown
        };
    }

    protected static function mapPureHardwareType(string $type): string
    {
        return match (strtolower($type)) {
            'controller', 'ch' => 'module',
            'drive', 'shelf', 'ssd' => 'container',
            'psu', 'power supply' => 'powerSupply',
            'fan' => 'fan',
            'eth', 'fc' => 'port',
            default => 'other',
        };
    }

    protected static function toStatus($v): string
    {
        if (is_bool($v)) {
            return $v ? 'up' : 'down';
        }

        $str = strtolower((string)$v);
        return match ($str) {
            'up', 'online', 'active', 'enabled', 'healthy', 'ok', '1', 'true' => 'up',
            'down', 'offline', 'inactive', 'disabled', 'failed', '0', 'false' => 'down',
            'testing', 'initializing', 'starting' => 'testing',
            default => 'unknown',
        };
    }

    protected static function stableIndexFromName(string $name): int
    {
        // Use CRC32 to generate a stable numeric index
        // This ensures the same name always gets the same index
        return abs(crc32($name));
    }

    protected static function netmaskToCidr(string $netmask): int
    {
        // Convert netmask to CIDR prefix length
        // e.g., "255.255.255.0" => 24
        $long = ip2long($netmask);
        $base = ip2long('255.255.255.255');
        return (int) (32 - log(($long ^ $base) + 1, 2));
    }
}