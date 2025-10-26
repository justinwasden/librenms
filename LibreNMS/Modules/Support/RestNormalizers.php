<?php

namespace LibreNMS\Modules\Support;

/**
 * REST API response normalizers
 * Transform vendor-specific API responses into LibreNMS standard format
 */
class RestNormalizers
{
    // ========================================
    // Pure Storage FlashArray Normalizers
    // ========================================

    /**
     * Normalize Pure Storage array-level sensors (performance and capacity)
     *
     * @param array $arrayPayload Response from /arrays endpoint
     * @param array $perfPayload Response from /arrays/performance endpoint
     * @return array Sensors in LibreNMS format
     */
    public static function normalizePureArraySensors(array $arrayPayload, array $perfPayload): array
    {
        $sensors = [];

        // Array info from /arrays endpoint
        if (isset($arrayPayload['items']) && is_array($arrayPayload['items'])) {
            foreach ($arrayPayload['items'] as $array) {
                $arrayName = $array['name'] ?? 'array';

                // Capacity sensors
                if (isset($array['capacity'])) {
                    $sensors[] = [
                        'sensor_class' => 'storage',
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

                // Read latency (microseconds)
                if (isset($perf['usec_per_read_op'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Read Latency',
                        'sensor_index' => 'array_read_latency',
                        'sensor_current' => $perf['usec_per_read_op'],
                        'sensor_limit' => 10000, // 10ms warning
                        'sensor_limit_low' => 0,
                    ];
                }

                // Write latency (microseconds)
                if (isset($perf['usec_per_write_op'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Write Latency',
                        'sensor_index' => 'array_write_latency',
                        'sensor_current' => $perf['usec_per_write_op'],
                        'sensor_limit' => 10000, // 10ms warning
                        'sensor_limit_low' => 0,
                    ];
                }

                // Queue depth
                if (isset($perf['queue_depth'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Queue Depth',
                        'sensor_index' => 'array_queue_depth',
                        'sensor_current' => $perf['queue_depth'],
                        'sensor_limit' => 1000,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage hardware components
     *
     * @param array $payload Response from /hardware endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
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
                    'sensor_descr' => $name,
                    'sensor_index' => 'hw_fan_' . $index,
                    'sensor_current' => $hw['speed'],
                    'sensor_limit' => 20000,
                    'sensor_limit_low' => 1000,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    /**
     * Normalize Pure Storage network interfaces to LibreNMS ports
     *
     * @param array $payload Response from /network-interfaces endpoint
     * @return array Ports in LibreNMS format
     */
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

            // Convert speed to bits per second (Pure returns in Gbps)
            $speedBps = $speed * 1000000000;

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

    /**
     * Normalize Pure Storage network performance
     * Convert rates to counters for RRD storage
     *
     * @param array $payload Response from /network-interfaces/performance endpoint
     * @param int $pollIntervalSec Polling interval in seconds
     * @return array Port statistics
     */
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

    /**
     * Normalize Pure Storage port optics (SFP/QSFP sensors)
     *
     * @param array $payload Response from /ports endpoint
     * @return array Optics sensors
     */
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

    /**
     * Normalize Pure Storage volumes
     *
     * @param array $volumesPayload Response from /volumes endpoint
     * @param array $volPerfPayload Response from /volumes/performance endpoint
     * @return array Volume sensors
     */
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
                    'sensor_class' => 'storage',
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
                        'sensor_index' => 'vol_lat_' . $index,
                        'sensor_current' => $avgLatency,
                        'sensor_limit' => 10000,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage attached hosts
     *
     * @param array $payload Response from /hosts endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
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
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'connected'],
                ],
            ];

            // Host space usage if available
            if (isset($host['space']) && isset($host['space']['total_physical'])) {
                $sensors[] = [
                    'sensor_class' => 'storage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => 'Host ' . $name . ' Space Used',
                    'sensor_index' => 'host_space_' . $index,
                    'sensor_current' => $host['space']['total_physical'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    // ========================================
    // Proxmox Normalizers
    // ========================================

    /**
     * Normalize Proxmox node status
     *
     * @param array $payload Response from /api2/json/nodes/{node}/status endpoint
     * @return array ['sensors' => [...], 'processors' => [...], 'mempools' => [...]]
     */
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
                'mempool_used' => $memUsed,
                'mempool_free' => $memTotal - $memUsed,
                'mempool_total' => $memTotal,
                'mempool_perc' => round($memPercent, 2),
            ];
        }

        // Root filesystem usage
        if (isset($data['rootfs']) && isset($data['rootfs']['used']) && isset($data['rootfs']['total'])) {
            $rootUsed = $data['rootfs']['used'];
            $rootTotal = $data['rootfs']['total'];
            $rootPercent = ($rootTotal > 0) ? ($rootUsed / $rootTotal) * 100 : 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Root FS Usage',
                'sensor_index' => 'node_rootfs',
                'sensor_current' => round($rootPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        // Uptime
        if (isset($data['uptime'])) {
            $sensors[] = [
                'sensor_class' => 'runtime',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Uptime',
                'sensor_index' => 'node_uptime',
                'sensor_current' => $data['uptime'],
                'sensor_limit' => null,
                'sensor_limit_low' => 0,
            ];
        }

        // Load average
        if (isset($data['loadavg']) && is_array($data['loadavg'])) {
            $load1 = $data['loadavg'][0] ?? 0;
            $sensors[] = [
                'sensor_class' => 'load',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Load Average (1m)',
                'sensor_index' => 'node_load1',
                'sensor_current' => $load1,
                'sensor_limit' => 10,
                'sensor_limit_low' => 0,
            ];
        }

        return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
    }

    /**
     * Normalize Proxmox node network interfaces
     *
     * @param array $payload Response from /api2/json/nodes/{node}/network endpoint
     * @return array Ports in LibreNMS format
     */
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

    /**
     * Normalize Proxmox storage pools
     *
     * @param array $payload Response from /api2/json/storage endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
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

            // Storage enabled state
            $isEnabled = ($storage['enabled'] ?? 1) ? 2 : 0;
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Storage ' . $name . ' Status',
                'sensor_index' => 'storage_state_' . $index,
                'sensor_current' => $isEnabled,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'disabled'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'enabled'],
                ],
            ];
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    /**
     * Normalize Proxmox cluster status
     *
     * @param array $payload Response from /api2/json/cluster/status endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
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
                // Cluster quorum state
                if (isset($item['quorate'])) {
                    $hasQuorum = $item['quorate'] ? 2 : 0;
                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'proxmox',
                        'sensor_descr' => 'Cluster Quorum',
                        'sensor_index' => 'cluster_quorum',
                        'sensor_current' => $hasQuorum,
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'no quorum'],
                            ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                            ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'quorate'],
                        ],
                    ];
                }

                // Cluster nodes count
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

    /**
     * Normalize Proxmox cluster resources (VMs, containers)
     *
     * @param array $payload Response from /api2/json/cluster/resources endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
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

    // ========================================
    // Helper Functions
    // ========================================

    /**
     * Convert Pure Storage status strings to numeric values
     *
     * @param string $status
     * @return int
     */
    protected static function pureStatusToNumeric(string $status): int
    {
        return match (strtolower($status)) {
            'healthy', 'ok', 'normal' => 2,
            'degraded', 'warning' => 1,
            'critical', 'failed', 'unhealthy' => 0,
            default => 3, // unknown
        };
    }

    /**
     * Map Pure Storage hardware type to entPhysicalClass
     *
     * @param string $type
     * @return string
     */
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

    /**
     * Convert status to standardized string (up/down/testing/unknown)
     *
     * @param mixed $v
     * @return string
     */
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

    /**
     * Generate stable numeric index from name (for ifIndex, entPhysicalIndex, etc.)
     *
     * @param string $name
     * @return int
     */
    protected static function stableIndexFromName(string $name): int
    {
        // Use CRC32 to generate a stable numeric index
        // This ensures the same name always gets the same index
        return abs(crc32($name));
    }
}
