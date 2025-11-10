<?php

namespace LibreNMS\Modules\Support;

class RestNormalizers
{
    // Existing Pure normalizers (as provided)
    public static function normalizePureArraySensors($device, $arrayPayload, $perfPayload = []): array
    {
        // Handle when called with wrong types (e.g., from TransformRunner fallback)
        if (!is_array($arrayPayload)) {
            return [];
        }
        if (!is_array($perfPayload)) {
            $perfPayload = [];
        }

        $sensors = [];

        // Array info from /arrays endpoint
        if (isset($arrayPayload['items']) && is_array($arrayPayload['items'])) {
            foreach ($arrayPayload['items'] as $array) {
                $arrayName = $array['name'] ?? 'array';

                // Capacity sensors - convert to TB for display
                if (isset($array['capacity'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Total Capacity (TB)',
                        'sensor_index' => 'array_capacity_total',
                        'sensor_current' => round(($array['capacity'] ?? 0) / 1099511627776, 2),
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                    ];
                }

                if (isset($array['space'])) {
                    $space = $array['space'];

                    // Total provisioned capacity - convert to TB
                    if (isset($space['total_provisioned'])) {
                        $sensors[] = [
                            'sensor_class' => 'count',
                            'sensor_type' => 'purestorage',
                            'sensor_descr' => $arrayName . ' Total Provisioned (TB)',
                            'sensor_index' => 'array_total_provisioned',
                            'sensor_current' => round($space['total_provisioned'] / 1099511627776, 2),
                            'sensor_limit' => null,
                            'sensor_limit_low' => 0,
                        ];
                    }

                    // Data reduction ratio - display as X.X to 1 format
                    // e.g., 3.5:1 ratio is stored as 3.5
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

        // Also extract storage and mempool data from Pure Storage arrays
        $storage = [];
        $mempools = [];

        \Illuminate\Support\Facades\Log::debug('normalizePureArraySensors - extracting storage', [
            'has_items' => isset($arrayPayload['items']),
            'items_count' => is_array($arrayPayload['items'] ?? null) ? count($arrayPayload['items']) : 0,
        ]);

        if (isset($arrayPayload['items']) && is_array($arrayPayload['items'])) {
            foreach ($arrayPayload['items'] as $array) {
                $arrayName = $array['name'] ?? 'array';

                \Illuminate\Support\Facades\Log::debug('Processing array for storage', [
                    'array_name' => $arrayName,
                    'has_capacity' => isset($array['capacity']),
                    'has_space' => isset($array['space']),
                ]);

                // Storage data from capacity and space
                if (isset($array['capacity']) && isset($array['space'])) {
                    $totalCapacity = $array['capacity'];
                    $totalPhysical = $array['space']['total_physical'] ?? 0;
                    $free = $totalCapacity - $totalPhysical;

                    $storageEntry = [
                        'storage_index' => 'array_' . ($array['id'] ?? '0'),
                        'storage_descr' => $arrayName . ' Capacity',
                        'storage_type' => 'flasharray',
                        'storage_size' => $totalCapacity,
                        'storage_used' => $totalPhysical,
                        'storage_free' => $free > 0 ? $free : 0,
                        'storage_units' => 1,
                        'storage_perc' => $totalCapacity > 0 ? round(($totalPhysical / $totalCapacity) * 100, 2) : 0,
                    ];
                    $storage[] = $storageEntry;

                    \Illuminate\Support\Facades\Log::debug('Created storage entry', $storageEntry);
                }
            }
        }

        \Illuminate\Support\Facades\Log::debug('normalizePureArraySensors - final counts', [
            'sensors' => count($sensors),
            'storage' => count($storage),
            'mempools' => count($mempools),
        ]);

        // Extract mempool data from performance metrics if available
        if (isset($perfPayload['items']) && is_array($perfPayload['items'])) {
            foreach ($perfPayload['items'] as $perf) {
                $arrayName = $perf['name'] ?? 'array';

                // Queue depth can indicate memory pressure
                if (isset($perf['queue_depth'])) {
                    $queueDepth = $perf['queue_depth'];
                    // Assume max queue depth of 1000 for percentage calculation
                    $maxQueue = 1000;
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
            }
        }

        return [
            'sensors' => $sensors,
            'storage' => $storage,
            'mempools' => $mempools,
        ];
    }
    public static function normalizePureHardware($device, $payload): array
    {
        // Handle when called with wrong types (e.g., from TransformRunner fallback)
        if (!is_array($payload)) {
            return ['sensors' => [], 'inventory' => []];
        }

        $sensors = [];
        $inventory = [];
        $parentIndices = []; // Track parent device indices for hierarchy

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        // First pass: Identify all potential parent devices and build index map
        // A parent device is one that doesn't contain a dot (not a child component)
        // Examples: CT0, CT1, CH0, CH1, SH0, SH1, NODE0, etc.
        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';

            // If name doesn't contain a dot, it's a potential parent
            if (strpos($name, '.') === false) {
                $index = self::stableIndexFromName($name);
                // Store with case-insensitive key for matching
                $parentIndices[strtoupper($name)] = $index;
            }
        }

        // Second pass: Create all inventory items with proper hierarchy
        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';
            $type = $hw['type'] ?? 'unknown';
            $status = $hw['status'] ?? 'unknown';

            // Skip NVRAM (NVB) and drive bay (BAY) devices
            // These can come from /hardware endpoint (type: drive_bay, nvram_bay)
            // or /drives endpoint (type: SSD, NVRAM)
            $typeLower = strtolower($type);
            if ($typeLower === 'nvram_bay' || $typeLower === 'drive_bay' || $typeLower === 'ssd' || $typeLower === 'nvram') {
                continue;
            }

            $index = self::stableIndexFromName($name);

            // Determine parent device
            $parentIndex = 0;
            // Check if name contains a dot (e.g., "CT0.FC0", "CH1.PSU0", "SH0.DISK1")
            if (strpos($name, '.') !== false) {
                // Extract parent name (everything before the first dot)
                $parts = explode('.', $name, 2);
                $parentName = strtoupper($parts[0]);

                // Look up parent index
                if (isset($parentIndices[$parentName])) {
                    $parentIndex = $parentIndices[$parentName];
                }
            }

            // Inventory entry
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => $name,
                'entPhysicalClass' => self::mapPureHardwareType($type),
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $hw['model'] ?? '',
                'entPhysicalSerialNum' => $hw['serial'] ?? '',
                'entPhysicalContainedIn' => $parentIndex,
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

		foreach ($payload['items'] as $perf) { // Assuming $payload is the direct API response
		        $name = $perf['name'] ?? '';
		        $ifIndex = self::stableIndexFromName($name);
		        $eth = $perf['eth'] ?? [];

		        // 1. Convert Bytes/sec rate to a Counter Delta
		        $rxBytes = (float)($eth['received_bytes_per_sec'] ?? 0) * $pollIntervalSec;
		        $txBytes = (float)($eth['transmitted_bytes_per_sec'] ?? 0) * $pollIntervalSec;

		        // 2. Convert Packet/sec rate to a Counter Delta
		        $rxPkts = (float)($eth['received_packets_per_sec'] ?? 0) * $pollIntervalSec;
		        $txPkts = (float)($eth['transmitted_packets_per_sec'] ?? 0) * $pollIntervalSec;

		        // 3. Aggregate Error Deltas (CRC/Frame errors are common RX errors)
		        $inErrors = (float)($eth['received_crc_errors_per_sec'] ?? 0) + (float)($eth['received_frame_errors_per_sec'] ?? 0);
		        $outErrors = (float)($eth['transmitted_dropped_errors_per_sec'] ?? 0); // Assuming dropped errors are the main TX error

		        $stats[$ifIndex] = [
		            // Note: These must be cast to integer or rounded, as DB expects BIGINT counters.
		            'ifInOctets' => (int) $rxBytes,
		            'ifOutOctets' => (int) $txBytes,
		            'ifInErrors' => (int) ($inErrors * $pollIntervalSec),
		            'ifOutErrors' => (int) ($outErrors * $pollIntervalSec),
		            'ifInUcastPkts' => (int) $rxPkts,
		            'ifOutUcastPkts' => (int) $txPkts,
		            'ifInDiscards' => 0, // Not provided by API, assume 0
		            'ifOutDiscards' => (int) ($eth['transmitted_dropped_errors_per_sec'] ?? 0 * $pollIntervalSec),
		            // The rest are complex/optional, set to 0 or null if not used
		        ];
		    }
		    return $stats;
		}

    public static function normalizePurePortOptics($device, $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $port) {
            $name = $port['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Optical power sensors (dBm) - only if this is an FC/optical port
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
    public static function normalizePureVolumes($device, $volumesPayload, $volPerfPayload = []): array
    {
        if (!is_array($volumesPayload)) {
            return [];
        }
        if (!is_array($volPerfPayload)) {
            $volPerfPayload = [];
        }

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

            // Volume size - convert to TB for display
            if (isset($vol['provisioned'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => 'Vol ' . $name . ' Provisioned (TB)',
                    'sensor_index' => 'vol_prov_' . $index,
                    'sensor_current' => round($vol['provisioned'] / 1099511627776, 2),
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
    public static function normalizePureHosts($device, $payload): array
    {
        if (!is_array($payload)) {
            return ['sensors' => [], 'inventory' => []];
        }

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

            // NOTE: Host connection state and count sensors have been removed.
            // This data is now stored in the storage_hosts table with columns:
            // - port_connectivity_status (e.g., 'healthy', 'degraded', 'unhealthy')
            // - port_connectivity_details (e.g., connection count and port details)
            // See storage_hosts table and FlashArrayClient::fetchHosts() for current implementation.
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    public static function normalizePureAlerts($device, $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        // Count alerts by severity
        $critical = 0;
        $warning = 0;
        $info = 0;

        foreach ($payload['items'] as $alert) {
            $severity = strtolower($alert['severity'] ?? 'info');
            if ($severity === 'critical') {
                $critical++;
            } elseif ($severity === 'warning') {
                $warning++;
            } else {
                $info++;
            }
        }

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'purestorage',
            'sensor_descr' => 'Critical Alerts',
            'sensor_index' => 'alerts_critical',
            'sensor_current' => $critical,
            'sensor_limit' => 10,
            'sensor_limit_low' => null,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'purestorage',
            'sensor_descr' => 'Warning Alerts',
            'sensor_index' => 'alerts_warning',
            'sensor_current' => $warning,
            'sensor_limit' => 20,
            'sensor_limit_low' => null,
        ];

        return $sensors;
    }

    public static function normalizePureArrayPerfByLink(array $payload): array
    {
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $perf) {
            $name = $perf['name'] ?? 'array';
            $index = self::stableIndexFromName($name);

            if (isset($perf['queue_depth'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Queue Depth',
                    'sensor_index' => 'queue_depth_' . $index,
                    'sensor_current' => $perf['queue_depth'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizePureArrayConnections(array $payload): array
    {
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $inventory;
        }

        foreach ($payload['items'] as $idx => $conn) {
            $name = $conn['array_name'] ?? 'connection_' . $idx;
            $index = self::stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 10000,
                'entPhysicalDescr' => 'Array Connection: ' . $name,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $conn['type'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'array-connection',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $conn['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return $inventory;
    }

    public static function normalizePureConnections($device, $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $inventory;
        }

        foreach ($payload['items'] as $idx => $conn) {
            $host = $conn['host']['name'] ?? 'host_' . $idx;
            $volume = $conn['volume']['name'] ?? 'volume_' . $idx;
            $name = $host . '_' . $volume;
            $index = self::stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 20000,
                'entPhysicalDescr' => 'Connection: ' . $host . ' -> ' . $volume,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $conn['protocol'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'host-volume-connection',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => $conn['lun'] ?? '',
            ];
        }

        return $inventory;
    }

    public static function normalizePureControllers(array $payload): array
    {
        $inventory = [];
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['inventory' => $inventory, 'sensors' => $sensors];
        }

        foreach ($payload['items'] as $ctrl) {
            $name = $ctrl['name'] ?? 'controller';
            $index = self::stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 30000,
                'entPhysicalDescr' => 'Controller: ' . $name,
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $ctrl['model'] ?? '',
                'entPhysicalSerialNum' => $ctrl['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'controller',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $ctrl['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Controller status sensor
            if (isset($ctrl['status'])) {
                $statusMap = ['ok' => 2, 'critical' => 0, 'warning' => 1, 'unknown' => 3];
                $statusValue = $statusMap[strtolower($ctrl['status'])] ?? 3;

                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Status',
                    'sensor_index' => 'ctrl_status_' . $index,
                    'sensor_current' => $statusValue,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'critical'],
                        ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'warning'],
                        ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'ok'],
                        ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ],
                ];
            }
        }

        return ['inventory' => $inventory, 'sensors' => $sensors];
    }

    public static function normalizePurePortDetails(array $payload): array
    {
        $transceivers = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $transceivers;
        }

        foreach ($payload['items'] as $port) {
            $name = $port['name'] ?? 'unknown';
            $ifIndex = self::stableIndexFromName($name);

            // Pure Storage transceiver data is under items.static
            $static = $port['static'] ?? [];

            if (!empty($static)) {
                // Parse distance from string like "Copper Cable: 1 m" or "Single-mode Fiber: 10 km" to integer meters
                $distance = null;
                if (isset($static['link_length'])) {
                    $linkLength = $static['link_length'];
                    if (preg_match('/(\d+(?:\.\d+)?)\s*(m|km)/i', $linkLength, $matches)) {
                        $value = (float) $matches[1];
                        $unit = strtolower($matches[2]);
                        $distance = $unit === 'km' ? (int) ($value * 1000) : (int) $value;
                    }
                }

                $trans = [
                    'ifName' => $name,
                    'index' => $ifIndex,
                    'type' => $static['identifier'] ?? null,
                    'vendor' => $static['vendor_name'] ?? null,
                    'oui' => $static['vendor_oui'] ?? null,
                    'model' => $static['vendor_part_number'] ?? null,
                    'revision' => $static['vendor_revision'] ?? null,
                    'serial' => $static['vendor_serial_number'] ?? null,
                    'date' => $static['vendor_date_code'] ?? null,
                    'connector' => $static['connector_type'] ?? null,
                    'distance' => $distance,
                    'wavelength' => $static['wavelength'] ?? null,
                    'cable' => isset($static['cable_technology']) && is_array($static['cable_technology'])
                        ? implode(', ', $static['cable_technology'])
                        : ($static['cable_technology'] ?? null),
                    'encoding' => $static['encoding'] ?? null,
                    'channels' => $static['channels'] ?? 1,
                ];
                $transceivers[] = $trans;
            }
        }

        return $transceivers;
    }

    public static function normalizePureVolumePerfByArray(array $payload): array
    {
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $perf) {
            $name = $perf['name'] ?? 'volume';
            $index = self::stableIndexFromName($name);

            if (isset($perf['queue_depth'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Queue Depth',
                    'sensor_index' => 'vol_queue_' . $index,
                    'sensor_current' => $perf['queue_depth'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizePureSubnets(array $payload): array
    {
        $networks = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $networks;
        }

        foreach ($payload['items'] as $subnet) {
            $prefix = $subnet['prefix'] ?? $subnet['subnet'] ?? null;

            if ($prefix) {
                $networks[] = [
                    'ipv4_network' => $prefix,
                    'context_name' => $subnet['name'] ?? null,
                ];
            }
        }

        return $networks;
    }

		//ProxMox
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

            // Get MAC address from hwaddr field, not from address (which is IP)
            $macAddress = '';
            if (!empty($iface['hwaddr'])) {
                $macAddress = strtolower($iface['hwaddr']);
            }

            $ports[] = [
                'ifIndex' => $idx + 1,  // Use sequential index instead of CRC32 hash
                'ifName' => $name,
                'ifDescr' => $iface['comments'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => 1000000000, // Default to 1Gbps
                'ifOperStatus' => $active,
                'ifAdminStatus' => ($iface['autostart'] ?? 1) ? 'up' : 'down',
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $macAddress, // Fixed: use hwaddr, not address (IP)
                'ifAlias' => $iface['comments'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }

    public static function normalizeProxmoxIpv4(array $payload): array
    {
        $addresses = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $addresses;
        }

        foreach ($payload['data'] as $iface) {
            $ifName = $iface['iface'] ?? '';
            $cidr = $iface['cidr'] ?? null;
            $ipAddr = $iface['address'] ?? null;
            $netmask = $iface['netmask'] ?? null;

            if (!$ifName || (!$cidr && !$ipAddr)) {
                continue;
            }

            $prefixLen = 24; // Default safe value

            if ($cidr && strpos($cidr, '/') !== false) {
                [$ipAddr, $prefixLen] = explode('/', $cidr, 2);
            } elseif ($ipAddr && $netmask) {
                // If netmask is a number (CIDR) or a dotted quad
                $prefixLen = is_numeric($netmask) ? (int)$netmask : self::netmaskToCidr($netmask);
            } elseif ($ipAddr && is_numeric($netmask)) {
                $prefixLen = (int)$netmask;
            }

            if ($ipAddr) {
                $addresses[] = [
                    // Persistor will resolve port_id via ifName
                    'ifName' => $ifName,
                    'ipv4_address' => $ipAddr,
                    'ipv4_prefixlen' => $prefixLen,
                    'context_name' => '',
                ];
            }
        }

        return $addresses;
    }

    public static function normalizeProxmoxNetworkStatistics(array $payload): array
    {
        // Parse Proxmox RRD data for network statistics
        // Input: /nodes/{node}/rrddata?timeframe=hour
        // Output: structured array ['ports_statistics' => [...]] for DeviceApiPersistor::savePortsStatistics()
        //
        // NOTE: Proxmox rrddata provides NODE-LEVEL aggregate traffic (netin/netout),
        // not per-interface statistics. We apply this to vmbr0 (main bridge) or first active interface.

        $stats = [];

        if (!isset($payload['data']) || !is_array($payload['data']) || empty($payload['data'])) {
            return ['ports_statistics' => $stats];
        }

        // Get the latest data point (most recent)
        $latestData = end($payload['data']);
        if (!$latestData || !isset($latestData['time'])) {
            return ['ports_statistics' => $stats];
        }

        $pollTime = (int) $latestData['time'];
        $pollPeriod = 300; // Default 5 minute interval

        // Extract aggregate node network traffic
        $netin = isset($latestData['netin']) ? (float) $latestData['netin'] : null;
        $netout = isset($latestData['netout']) ? (float) $latestData['netout'] : null;

        \Log::debug('normalizeProxmoxNetworkStatistics: extracted values', [
            'netin' => $netin,
            'netout' => $netout,
            'poll_time' => $pollTime,
        ]);

        // Only create statistics if we have data
        if ($netin !== null || $netout !== null) {
            // Apply to vmbr0 (main bridge interface) by default
            // This represents the aggregate node traffic through the main bridge
            $stats[] = [
                'ifName' => 'vmbr0',  // Main Proxmox bridge interface
                'poll_time' => $pollTime,
                'poll_period' => $pollPeriod,
                'ifInOctets_rate' => $netin,
                'ifOutOctets_rate' => $netout,
                'ifInBits_rate' => $netin !== null ? $netin * 8 : null,
                'ifOutBits_rate' => $netout !== null ? $netout * 8 : null,
            ];

            \Log::debug('normalizeProxmoxNetworkStatistics: created statistics entry', [
                'stats_count' => count($stats),
                'stats' => $stats,
            ]);
        }

        \Log::debug('normalizeProxmoxNetworkStatistics: returning', [
            'stats_count' => count($stats),
        ]);

        return ['ports_statistics' => $stats];
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

		//FortiGate
		public static function normalizeFortigateSystemUsage(array $payload): array
    {
        $sensors = [];
        $processors = [];
        $mempools = [];

        $results = $payload['results'] ?? $payload;

        // CPU usage - extract current value from array structure
        if (isset($results['cpu'])) {
            $cpuValue = $results['cpu'];

            // If cpu is an array with 'current' field, extract it
            if (is_array($cpuValue)) {
                if (isset($cpuValue[0]['current'])) {
                    $cpuValue = $cpuValue[0]['current'];
                } elseif (isset($cpuValue['current'])) {
                    $cpuValue = $cpuValue['current'];
                } else {
                    $cpuValue = null; // Skip if format is unexpected
                }
            }

            if ($cpuValue !== null && is_numeric($cpuValue)) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'CPU Usage',
                    'sensor_index' => 'cpu_usage',
                    'sensor_current' => $cpuValue,
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];

                $processors[] = [
                    'processor_index' => 0,
                    'processor_type' => 'fortigate-cpu',
                    'processor_descr' => 'System CPU',
                    'processor_usage' => $cpuValue,
                ];
            }
        }

        // Memory usage - extract current value from array structure
        if (isset($results['mem'])) {
            $memValue = $results['mem'];

            // If mem is an array with 'current' field, extract it
            if (is_array($memValue)) {
                if (isset($memValue[0]['current'])) {
                    $memValue = $memValue[0]['current'];
                } elseif (isset($memValue['current'])) {
                    $memValue = $memValue['current'];
                } else {
                    $memValue = null; // Skip if format is unexpected
                }
            }

            if ($memValue !== null && is_numeric($memValue)) {
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'Memory Usage',
                    'sensor_index' => 'mem_usage',
                    'sensor_current' => $memValue,
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];

                // Check if absolute memory values are provided (in KB, MB, or bytes)
                $memTotal = $results['mem_total'] ?? $results['memory_total'] ?? null;
                $memUsed = $results['mem_used'] ?? $results['memory_used'] ?? null;

                // If absolute values not available, use percentage-based approach
                // Scale to 100 for percentage representation (mem field is already a percentage)
                if ($memTotal === null || $memUsed === null || !is_numeric($memTotal) || !is_numeric($memUsed)) {
                    $memTotal = 100;
                    $memUsed = $memValue;
                }

                $mempools[] = [
                    'mempool_index' => 0,
                    'mempool_type' => 'fortigate',
                    'mempool_descr' => 'System Memory',
                    'mempool_total' => (int)$memTotal,
                    'mempool_used' => (int)$memUsed,
                    'mempool_free' => (int)$memTotal - (int)$memUsed,
                    'mempool_perc' => $memValue,
                ];
            }
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
        $ipv4_addresses = [];
        $ipv4_mac = [];
        $ports_statistics = [];
        $transceivers = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return ['ports' => $ports];
        }

        foreach ($results as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";
            $status = strtolower($iface['status'] ?? 'down');
            $ifIndex = self::stableIndexFromName($name);

            // Parse speed - handle numeric values and string formats like "1000", "auto", "1G", etc.
            $speed = $iface['speed'] ?? 1000;
            if (is_numeric($speed)) {
                $speedBps = ((int)$speed) * 1000000; // Mbps to bps
            } else {
                // Handle string formats like "auto", "1G", etc. - default to 1Gbps
                $speedBps = 1000000000;
            }

            $ports[] = [
                'ifIndex' => $ifIndex,
                'ifName' => $name,
                'ifDescr' => $iface['alias'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $speedBps,
                'ifOperStatus' => $status === 'up' ? 'up' : 'down',
                'ifAdminStatus' => $status === 'up' ? 'up' : 'down',
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['macaddr'] ?? '',
                'ifAlias' => $iface['alias'] ?? '',
                'ifLastChange' => 0,
            ];

            // Extract IPv4 addresses if present
            if (isset($iface['ip']) && $iface['ip'] !== '0.0.0.0') {
                $ip = $iface['ip'];
                if (strpos($ip, '/') !== false) {
                    [$ipAddr, $prefixLen] = explode('/', $ip, 2);
                } else {
                    $ipAddr = $ip;
                    $prefixLen = isset($iface['netmask']) ? self::netmaskToCidr($iface['netmask']) : 24;
                }

                $ipv4_addresses[] = [
                    'ifIndex' => $ifIndex,
                    'ipv4_address' => $ipAddr,
                    'ipv4_prefixlen' => $prefixLen,
                    'context_name' => '',
                ];
            }

            // Extract MAC address mappings if present (ARP data)
            if (isset($iface['arp']) && is_array($iface['arp'])) {
                foreach ($iface['arp'] as $arp) {
                    if (isset($arp['mac'], $arp['ip'])) {
                        $ipv4_mac[] = [
                            'ifIndex' => $ifIndex,
                            'mac_address' => $arp['mac'],
                            'ipv4_address' => $arp['ip'],
                            'context_name' => '',
                        ];
                    }
                }
            }

            // Extract traffic statistics if present
            if (isset($iface['rx_bytes']) || isset($iface['tx_bytes'])) {
                $ports_statistics[] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $name,  // Include ifName for matching when ifIndex doesn't match existing ports
                    'ifInOctets' => $iface['rx_bytes'] ?? 0,
                    'ifOutOctets' => $iface['tx_bytes'] ?? 0,
                    'ifInErrors' => $iface['rx_errors'] ?? 0,
                    'ifOutErrors' => $iface['tx_errors'] ?? 0,
                    'ifInUcastPkts' => $iface['rx_packets'] ?? 0,
                    'ifOutUcastPkts' => $iface['tx_packets'] ?? 0,
                    'ifInDiscards' => $iface['rx_dropped'] ?? 0,
                    'ifOutDiscards' => $iface['tx_dropped'] ?? 0,
                ];
            }

            // Extract transceiver/SFP data if present
            if (isset($iface['sfp']) && is_array($iface['sfp'])) {
                $sfp = $iface['sfp'];
                $transceivers[] = [
                    'ifIndex' => $ifIndex,
                    'index' => $ifIndex,
                    'type' => $sfp['type'] ?? null,
                    'vendor' => $sfp['vendor'] ?? null,
                    'model' => $sfp['part_number'] ?? null,
                    'serial' => $sfp['serial'] ?? null,
                    'connector' => $sfp['connector'] ?? null,
                ];
            }
        }

        // Return structured response with all available data
        $response = ['ports' => $ports];
        if (!empty($ipv4_addresses)) {
            $response['ipv4_addresses'] = $ipv4_addresses;
        }
        if (!empty($ipv4_mac)) {
            $response['ipv4_mac'] = $ipv4_mac;
        }
        if (!empty($ports_statistics)) {
            $response['ports_statistics'] = $ports_statistics;
        }
        if (!empty($transceivers)) {
            $response['transceivers'] = $transceivers;
        }

        return $response;
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
                $prefixLen = isset($iface['netmask']) && $iface['netmask'] ? self::netmaskToCidr($iface['netmask']) : 24;
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

    public static function normalizeFortigateInterfaceStats(array $payload): array
    {
        $statistics = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return $statistics;
        }

        foreach ($results as $iface) {
            $name = $iface['name'] ?? '';
            if (!$name) {
                continue;
            }

            $ifIndex = self::stableIndexFromName($name);

            $statistics[] = [
                'ifIndex' => $ifIndex,
                'ifInOctets' => $iface['rx_bytes'] ?? $iface['link']['stats']['rx_bytes'] ?? 0,
                'ifOutOctets' => $iface['tx_bytes'] ?? $iface['link']['stats']['tx_bytes'] ?? 0,
                'ifInErrors' => $iface['rx_errors'] ?? $iface['link']['stats']['rx_errors'] ?? 0,
                'ifOutErrors' => $iface['tx_errors'] ?? $iface['link']['stats']['tx_errors'] ?? 0,
                'ifInUcastPkts' => $iface['rx_packets'] ?? $iface['link']['stats']['rx_packets'] ?? 0,
                'ifOutUcastPkts' => $iface['tx_packets'] ?? $iface['link']['stats']['tx_packets'] ?? 0,
                'ifInDiscards' => $iface['rx_dropped'] ?? $iface['link']['stats']['rx_dropped'] ?? 0,
                'ifOutDiscards' => $iface['tx_dropped'] ?? $iface['link']['stats']['tx_dropped'] ?? 0,
            ];
        }

        return $statistics;
    }

    /**
     * Normalize FortiGate hardware sensor information (temperature, fan, voltage, power)
     * Input: GET /monitor/system/sensor-info
     */
    public static function normalizeFortgateSensorInfo(array $payload): array
    {
        $sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        foreach ($results as $sensor) {
            $name = $sensor['name'] ?? 'Unknown';
            $type = $sensor['type'] ?? 'unknown';
            $value = $sensor['value'] ?? 0;
            $alarm = $sensor['alarm'] ?? 0;

            // Determine sensor class based on type
            $sensorClass = match ($type) {
                'temperature' => 'temperature',
                'fan' => 'fanspeed',
                'voltage' => 'voltage',
                'wattage' => 'power',
                'power' => 'state',
                default => null,
            };

            if (!$sensorClass) {
                continue; // Skip unknown sensor types
            }

            // Create sensor index from name
            $index = self::stableIndexFromName($name);

            if ($sensorClass === 'state') {
                // Power supply status sensor
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => $name,
                    'sensor_index' => 'power_' . $index,
                    'sensor_current' => (int)$value,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'failed'],
                        ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'ok'],
                    ],
                ];
            } else {
                // Regular numeric sensor
                $sensors[] = [
                    'sensor_class' => $sensorClass,
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => $name,
                    'sensor_index' => $type . '_' . $index,
                    'sensor_current' => (float)$value,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }
        }

        return $sensors;
    }

    /**
     * Normalize FortiGate IPsec VPN tunnel information
     * Input: GET /monitor/vpn/ipsec
     */
    public static function normalizeFortigateVpnIpsec(array $payload): array
    {
        $sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        // Count active and total tunnels
        $activeCount = 0;
        $totalCount = count($results);

        foreach ($results as $tunnel) {
            $status = $tunnel['status'] ?? 'down';
            if (in_array(strtolower($status), ['up', 'established'])) {
                $activeCount++;
            }
        }

        // Add count sensors for VPN tunnels
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'IPsec VPN Tunnels Active',
            'sensor_index' => 'ipsec_active',
            'sensor_current' => $activeCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'IPsec VPN Tunnels Total',
            'sensor_index' => 'ipsec_total',
            'sensor_current' => $totalCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }

    /**
     * Normalize FortiGate SSL VPN user information
     * Input: GET /monitor/vpn/ssl
     */
    public static function normalizeFortigateVpnSsl(array $payload): array
    {
        $sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        $userCount = count($results);

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'SSL VPN Users Connected',
            'sensor_index' => 'ssl_vpn_users',
            'sensor_current' => $userCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }

    /**
     * Normalize FortiGate DHCP lease information
     * Input: GET /monitor/system/dhcp
     */
    public static function normalizeFortgateDhcp(array $payload): array
    {
        $sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        $leaseCount = count($results);

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'fortigate',
            'sensor_descr' => 'DHCP Leases Active',
            'sensor_index' => 'dhcp_leases',
            'sensor_current' => $leaseCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return $sensors;
    }

    /**
     * Normalize FortiGate license status information
     * Input: GET /monitor/license/status
     */
    public static function normalizeFortgateLicense(array $payload): array
    {
        $sensors = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $sensors;
        }

        foreach ($results as $license) {
            $name = $license['name'] ?? 'Unknown';
            $type = $license['type'] ?? '';
            $status = $license['status'] ?? 'unknown';

            // Skip if no name
            if (!$name || $name === 'Unknown') {
                continue;
            }

            // Create sensor index from name
            $index = self::stableIndexFromName($name);

            // Map status to numeric value
            $statusValue = match (strtolower($status)) {
                'valid', 'licensed' => 1,
                'expired' => 2,
                'invalid' => 3,
                default => 0, // unknown
            };

            // Add license status as state sensor
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'fortigate',
                'sensor_descr' => 'License ' . $name,
                'sensor_index' => 'license_' . $index,
                'sensor_current' => $statusValue,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'valid'],
                    ['value' => 2, 'generic' => 2, 'graph' => 0, 'descr' => 'expired'],
                    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'invalid'],
                ],
            ];

            // If there's a days remaining field, add it as count sensor
            if (isset($license['days']) && is_numeric($license['days'])) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'fortigate',
                    'sensor_descr' => 'Days left for ' . $name,
                    'sensor_index' => 'license_days_' . $index,
                    'sensor_current' => (int)$license['days'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    public static function normalizeJunosInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['interface-information']['physical-interface'] ?? $payload['interfaces'] ?? [];

        if (!is_array($interfaces)) {
            return [];
        }

        // Handle both single and multiple interfaces
        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $status = strtolower($iface['admin-status'] ?? $iface['oper-status'] ?? 'unknown');

            $ports[] = [
                'ifIndex' => $iface['snmp-index'] ?? $idx,
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => $iface['if-type'] ?? 'ethernetCsmacd',
                'ifOperStatus' => ($status === 'up') ? 'up' : 'down',
                'ifAdminStatus' => ($status === 'up') ? 'up' : 'down',
                'ifSpeed' => $iface['speed'] ?? 0,
                'ifMtu' => $iface['mtu'] ?? 1514,
                'ifPhysAddress' => $iface['hardware-physical-address'] ?? $iface['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeJunosInventory(array $payload): array
    {
        $inventory = [];
        $chassis = $payload['chassis-inventory']['chassis'] ?? $payload['chassis'] ?? [];

        if (!empty($chassis)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => $chassis['description'] ?? 'Juniper Chassis',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'Chassis',
                'entPhysicalModelName' => $chassis['model'] ?? '',
                'entPhysicalSerialNum' => $chassis['serial-number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Juniper',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'junos',
                'entPhysicalHardwareRev' => $chassis['hardware-version'] ?? '',
                'entPhysicalFirmwareRev' => $chassis['firmware-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeJunosSystem(array $payload): array
    {
        $sensors = [];
        $processors = [];

        // CPU usage
        if (isset($payload['system-cpu-information'])) {
            $cpuInfo = $payload['system-cpu-information'];
            $cpuUsage = $cpuInfo['cpu-usage'] ?? 0;

            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'junos-cpu',
                'processor_descr' => 'System CPU',
                'processor_usage' => $cpuUsage,
            ];
        }

        // Memory usage
        if (isset($payload['system-memory-information'])) {
            $memInfo = $payload['system-memory-information'];
            $memTotal = $memInfo['memory-total'] ?? 100;
            $memUsed = $memInfo['memory-used'] ?? 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'junos',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'mem_usage',
                'sensor_current' => $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0,
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        return ['sensors' => $sensors, 'processors' => $processors];
    }

    public static function normalizeDellSystem(array $payload): array
    {
        $inventory = [];
        $system = $payload['SystemInformation'] ?? $payload['system'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => $system['Model'] ?? 'Dell System',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'Chassis',
                'entPhysicalModelName' => $system['Model'] ?? '',
                'entPhysicalSerialNum' => $system['ServiceTag'] ?? $system['SerialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Dell',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'dell',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['BIOSVersion'] ?? '',
                'entPhysicalSoftwareRev' => $system['FirmwareVersion'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => $system['AssetTag'] ?? '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeDellInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['NetworkInterfaces'] ?? $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['Id'] ?? $iface['Name'] ?? "interface-$idx";
            $status = strtolower($iface['Status']['State'] ?? $iface['LinkStatus'] ?? 'unknown');

            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['Description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($status === 'enabled' || $status === 'up') ? 'up' : 'down',
                'ifAdminStatus' => ($status === 'enabled' || $status === 'up') ? 'up' : 'down',
                'ifSpeed' => ($iface['SpeedMbps'] ?? 0) * 1000000,
                'ifMtu' => $iface['MTUSize'] ?? 1500,
                'ifPhysAddress' => $iface['MACAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeDellSensors(array $payload): array
    {
        $sensors = [];
        $thermalData = $payload['Thermal'] ?? [];

        // Temperature sensors
        if (isset($thermalData['Temperatures'])) {
            foreach ($thermalData['Temperatures'] as $idx => $temp) {
                if (isset($temp['ReadingCelsius'])) {
                    $sensors[] = [
                        'sensor_class' => 'temperature',
                        'sensor_type' => 'dell',
                        'sensor_descr' => $temp['Name'] ?? "Temperature $idx",
                        'sensor_index' => "temp-$idx",
                        'sensor_current' => $temp['ReadingCelsius'],
                        'sensor_limit' => $temp['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $temp['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        // Fan sensors
        if (isset($thermalData['Fans'])) {
            foreach ($thermalData['Fans'] as $idx => $fan) {
                if (isset($fan['Reading'])) {
                    $sensors[] = [
                        'sensor_class' => 'fanspeed',
                        'sensor_type' => 'dell',
                        'sensor_descr' => $fan['Name'] ?? "Fan $idx",
                        'sensor_index' => "fan-$idx",
                        'sensor_current' => $fan['Reading'],
                        'sensor_limit' => $fan['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $fan['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeHpeSystem(array $payload): array
    {
        $inventory = [];
        $system = $payload['System'] ?? $payload;

        $inventory[] = [
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => $system['Model'] ?? 'HPE System',
            'entPhysicalClass' => 'chassis',
            'entPhysicalName' => 'Chassis',
            'entPhysicalModelName' => $system['Model'] ?? '',
            'entPhysicalSerialNum' => $system['SerialNumber'] ?? '',
            'entPhysicalContainedIn' => 0,
            'entPhysicalMfgName' => 'HPE',
            'entPhysicalParentRelPos' => -1,
            'entPhysicalVendorType' => 'hpe',
            'entPhysicalHardwareRev' => '',
            'entPhysicalFirmwareRev' => $system['BiosVersion'] ?? '',
            'entPhysicalSoftwareRev' => $system['Firmware'] ?? '',
            'entPhysicalIsFRU' => 0,
            'entPhysicalAlias' => '',
            'entPhysicalAssetID' => '',
        ];

        return ['inventory' => $inventory];
    }

    public static function normalizeHpeInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['EthernetInterfaces'] ?? $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['Id'] ?? "interface-$idx";
            $status = strtolower($iface['LinkStatus'] ?? $iface['Status']['State'] ?? 'unknown');

            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['Name'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($status === 'linkup' || $status === 'up') ? 'up' : 'down',
                'ifAdminStatus' => ($status === 'linkup' || $status === 'up') ? 'up' : 'down',
                'ifSpeed' => ($iface['SpeedMbps'] ?? 0) * 1000000,
                'ifMtu' => $iface['MTUSize'] ?? 1500,
                'ifPhysAddress' => $iface['MACAddress'] ?? $iface['PermanentMACAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeHpeSensors(array $payload): array
    {
        $sensors = [];
        $thermalData = $payload['Thermal'] ?? [];

        // Temperature sensors
        if (isset($thermalData['Temperatures'])) {
            foreach ($thermalData['Temperatures'] as $idx => $temp) {
                if (isset($temp['ReadingCelsius'])) {
                    $sensors[] = [
                        'sensor_class' => 'temperature',
                        'sensor_type' => 'hpe',
                        'sensor_descr' => $temp['Name'] ?? "Temperature $idx",
                        'sensor_index' => "temp-$idx",
                        'sensor_current' => $temp['ReadingCelsius'],
                        'sensor_limit' => $temp['UpperThresholdCritical'] ?? null,
                        'sensor_limit_low' => $temp['LowerThresholdCritical'] ?? null,
                    ];
                }
            }
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeNimbleArrays(array $payload): array
    {
        $storage = [];
        $arrays = $payload['data'] ?? $payload['arrays'] ?? [];

        foreach ($arrays as $idx => $array) {
            $name = $array['name'] ?? "array-$idx";
            $usageBytes = $array['usage_bytes'] ?? 0;
            $capacityBytes = $array['capacity_bytes'] ?? 0;

            $storage[] = [
                'storage_index' => "nimble-array-$idx",
                'storage_descr' => $name,
                'storage_type' => 'nimble-array',
                'storage_size' => $capacityBytes,
                'storage_used' => $usageBytes,
                'storage_free' => $capacityBytes - $usageBytes,
                'storage_units' => 1,
                'storage_perc' => $capacityBytes > 0 ? round(($usageBytes / $capacityBytes) * 100, 2) : 0,
            ];
        }

        return ['storage' => $storage];
    }

    public static function normalizeNimbleDisks(array $payload): array
    {
        $inventory = [];
        $disks = $payload['data'] ?? $payload['disks'] ?? [];

        foreach ($disks as $idx => $disk) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 10,
                'entPhysicalDescr' => "Disk: {$disk['serial']}",
                'entPhysicalClass' => 'disk',
                'entPhysicalName' => $disk['model'] ?? "Disk $idx",
                'entPhysicalModelName' => $disk['model'] ?? '',
                'entPhysicalSerialNum' => $disk['serial'] ?? '',
                'entPhysicalContainedIn' => 1,
                'entPhysicalMfgName' => 'HPE Nimble',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'nimble',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $disk['firmware_version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeNimbleStats(array $payload): array
    {
        $sensors = [];

        if (isset($payload['volume_stats'])) {
            $stats = $payload['volume_stats'];
            $iops = $stats['iops'] ?? 0;
            $throughput = $stats['throughput'] ?? 0;

            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'nimble',
                'sensor_descr' => 'IOPS',
                'sensor_index' => 'iops',
                'sensor_current' => $iops,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeNimbleInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['data'] ?? $payload['network_interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['name'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['link_status'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => ($iface['link_speed'] ?? 0) * 1000000,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeNutanixClusters(array $payload): array
    {
        $inventory = [];
        $clusters = $payload['entities'] ?? [];

        foreach ($clusters as $idx => $cluster) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Nutanix Cluster: {$cluster['name']}",
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $cluster['name'] ?? "Cluster $idx",
                'entPhysicalModelName' => 'Nutanix Cluster',
                'entPhysicalSerialNum' => $cluster['cluster_uuid'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Nutanix',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'nutanix',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $cluster['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeNutanixHosts(array $payload): array
    {
        $processors = [];
        $mempools = [];
        $hosts = $payload['entities'] ?? [];

        foreach ($hosts as $idx => $host) {
            $cpuUsage = $host['stats']['hypervisor_cpu_usage_ppm'] ?? 0;
            $cpuUsagePercent = $cpuUsage / 10000;

            $processors[] = [
                'processor_index' => $idx,
                'processor_type' => 'nutanix-host',
                'processor_descr' => $host['name'] ?? "Host $idx",
                'processor_usage' => $cpuUsagePercent,
            ];

            $memTotal = $host['memory_capacity_in_bytes'] ?? 0;
            $memUsed = $host['stats']['hypervisor_memory_usage_ppm'] ?? 0;
            $memUsedBytes = ($memTotal * $memUsed) / 1000000;

            $mempools[] = [
                'mempool_index' => $idx,
                'mempool_type' => 'nutanix',
                'mempool_descr' => $host['name'] ?? "Host $idx",
                'mempool_total' => $memTotal,
                'mempool_used' => $memUsedBytes,
                'mempool_free' => $memTotal - $memUsedBytes,
                'mempool_perc' => $memUsed / 10000,
            ];
        }

        return ['processors' => $processors, 'mempools' => $mempools];
    }

    public static function normalizeNutanixStorage(array $payload): array
    {
        $storage = [];
        $containers = $payload['entities'] ?? [];

        foreach ($containers as $idx => $container) {
            $usageBytes = $container['usage_stats']['storage.usage_bytes'] ?? 0;
            $capacityBytes = $container['max_capacity'] ?? 0;

            $storage[] = [
                'storage_index' => "nutanix-$idx",
                'storage_descr' => $container['name'] ?? "Storage $idx",
                'storage_type' => 'nutanix-container',
                'storage_size' => $capacityBytes,
                'storage_used' => $usageBytes,
                'storage_free' => $capacityBytes - $usageBytes,
                'storage_units' => 1,
                'storage_perc' => $capacityBytes > 0 ? round(($usageBytes / $capacityBytes) * 100, 2) : 0,
            ];
        }

        return ['storage' => $storage];
    }

    public static function normalizeIseNetworkDevices(array $payload): array
    {
        $inventory = [];
        $devices = $payload['SearchResult']['resources'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Network Device: {$device['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Device $idx",
                'entPhysicalModelName' => $device['NetworkDeviceGroupList'] ?? '',
                'entPhysicalSerialNum' => $device['id'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-ise',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeIseEndpoints(array $payload): array
    {
        $sensors = [];
        $endpoints = $payload['SearchResult']['resources'] ?? [];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'cisco-ise',
            'sensor_descr' => 'Total Endpoints',
            'sensor_index' => 'endpoints-total',
            'sensor_current' => count($endpoints),
            'sensor_limit' => null,
            'sensor_limit_low' => null,
        ];

        return ['sensors' => $sensors];
    }

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

    public static function normalizePanInventory(array $payload): array
    {
        $inventory = [];
        $system = $payload['result']['system'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Palo Alto Firewall',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'PA-Firewall',
                'entPhysicalModelName' => $system['model'] ?? '',
                'entPhysicalSerialNum' => $system['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Palo Alto Networks',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'paloalto',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['sw-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizePanInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['result']['ifnet']['entry'] ?? [];

        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['alias'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizePanSystem(array $payload): array
    {
        $sensors = [];
        $system = $payload['result']['system'] ?? [];

        // Session count
        if (isset($system['num-active-sessions'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'paloalto',
                'sensor_descr' => 'Active Sessions',
                'sensor_index' => 'sessions',
                'sensor_current' => $system['num-active-sessions'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeNxInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['ins_api']['outputs']['output']['body']['TABLE_interface']['ROW_interface'] ?? [];

        if (isset($interfaces['interface'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['interface'] ?? "interface-$idx";
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['desc'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['admin_state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifSpeed' => $iface['speed'] ?? 0,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['eth_hw_addr'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeNxInventory(array $payload): array
    {
        $inventory = [];
        $modules = $payload['ins_api']['outputs']['output']['body']['TABLE_modinfo']['ROW_modinfo'] ?? [];

        if (isset($modules['modinf'])) {
            $modules = [$modules];
        }

        foreach ($modules as $idx => $module) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => $module['modtype'] ?? "Module $idx",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $module['model'] ?? "Module $idx",
                'entPhysicalModelName' => $module['model'] ?? '',
                'entPhysicalSerialNum' => $module['serialnum'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-nexus',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeIosxrInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['Cisco-IOS-XR-pfi-im-cmd-oper:interfaces']['interface-xr']['interface'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['interface-name'] ?? "interface-$idx";
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'im-state-up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => $iface['bandwidth'] ?? 0,
                'ifMtu' => $iface['mtu'] ?? 1514,
                'ifPhysAddress' => $iface['address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeIosxrInventory(array $payload): array
    {
        $inventory = [];
        $entities = $payload['Cisco-IOS-XR-plat-chas-invmgr-oper:platform-inventory']['racks']['rack']['entities']['entity'] ?? [];

        foreach ($entities as $idx => $entity) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => $entity['description'] ?? "Entity $idx",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $entity['name'] ?? "Entity $idx",
                'entPhysicalModelName' => $entity['model-name'] ?? '',
                'entPhysicalSerialNum' => $entity['serial-number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-iosxr',
                'entPhysicalHardwareRev' => $entity['hardware-revision'] ?? '',
                'entPhysicalFirmwareRev' => $entity['firmware-revision'] ?? '',
                'entPhysicalSoftwareRev' => $entity['software-revision'] ?? '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeCucmInventory(array $payload): array
    {
        $inventory = [];
        $devices = $payload['return']['phone'] ?? [];

        if (isset($devices['name'])) {
            $devices = [$devices];
        }

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Phone: {$device['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Phone $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-cucm',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => $device['loadInformation'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeCalixDevices(array $payload): array
    {
        $inventory = [];
        $devices = $payload['devices'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => $device['type'] ?? "Device $idx",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Device $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Calix',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'calix',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $device['softwareVersion'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeCalixInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['description'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['operStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['adminStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['macAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeCalixSensors(array $payload): array
    {
        $sensors = [];

        if (isset($payload['subscribers'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'calix',
                'sensor_descr' => 'Total Subscribers',
                'sensor_index' => 'subscribers',
                'sensor_current' => $payload['subscribers'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeNdfcDevices(array $payload): array
    {
        $inventory = [];
        $devices = $payload['DATA'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Switch: {$device['logicalName']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['logicalName'] ?? "Device $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-ndfc',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $device['release'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeNdfcInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['DATA'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['ifName'] ?? "interface-$idx",
                'ifDescr' => $iface['description'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['operSt'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['adminSt'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeAristaSystem(array $payload): array
    {
        $inventory = [];

        if (isset($payload['modelName'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Arista Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $payload['hostname'] ?? 'Arista',
                'entPhysicalModelName' => $payload['modelName'] ?? '',
                'entPhysicalSerialNum' => $payload['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Arista',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'arista',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $payload['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeAristaInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $name => $iface) {
            $ports[] = [
                'ifIndex' => count($ports),
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['lineProtocolStatus'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['interfaceStatus'] ?? 'disabled') === 'connected' ? 'up' : 'down',
                'ifSpeed' => ($iface['bandwidth'] ?? 0),
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['physicalAddress'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeAristaSensors(array $payload): array
    {
        $sensors = [];
        $temps = $payload['tempSensors'] ?? [];

        foreach ($temps as $name => $temp) {
            $sensors[] = [
                'sensor_class' => 'temperature',
                'sensor_type' => 'arista',
                'sensor_descr' => $name,
                'sensor_index' => $name,
                'sensor_current' => $temp['currentTemperature'] ?? 0,
                'sensor_limit' => $temp['maxTemperature'] ?? null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeExtremeSystem(array $payload): array
    {
        $inventory = [];
        $system = $payload['openconfig-system:system']['state'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Extreme Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'Extreme',
                'entPhysicalModelName' => '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Extreme Networks',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'extreme',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeExtremeInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['openconfig-interfaces:interfaces']['interface'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $name = $iface['name'] ?? "interface-$idx";
            $state = $iface['state'] ?? [];

            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $name,
                'ifDescr' => $state['description'] ?? $name,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($state['oper-status'] ?? 'DOWN') === 'UP' ? 'up' : 'down',
                'ifAdminStatus' => ($state['admin-status'] ?? 'DOWN') === 'UP' ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => $state['mtu'] ?? 1500,
                'ifPhysAddress' => $state['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeExtremeSensors(array $payload): array
    {
        $sensors = [];
        // Extreme sensor implementation depends on their specific API structure
        return ['sensors' => $sensors];
    }

    public static function normalizeBrocadeSystem(array $payload): array
    {
        $inventory = [];
        $chassis = $payload['Response']['chassis'] ?? [];

        if (!empty($chassis)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Brocade Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $chassis['chassis-user-friendly-name'] ?? 'Brocade',
                'entPhysicalModelName' => $chassis['product-name'] ?? '',
                'entPhysicalSerialNum' => $chassis['serial-number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Brocade',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'brocade',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $chassis['firmware-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeBrocadeInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['Response']['fibrechannel'] ?? [];

        if (isset($interfaces['name'])) {
            $interfaces = [$interfaces];
        }

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "port-$idx",
                'ifDescr' => $iface['user-friendly-name'] ?? "port-$idx",
                'ifType' => 'fibreChannel',
                'ifOperStatus' => ($iface['operational-state'] ?? 0) === 2 ? 'up' : 'down',
                'ifAdminStatus' => ($iface['enabled-state'] ?? 0) === 2 ? 'up' : 'down',
                'ifSpeed' => ($iface['speed'] ?? 0) * 1000000000,
                'ifMtu' => 2112,
                'ifPhysAddress' => null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeSonicSystem(array $payload): array
    {
        $inventory = [];
        $system = $payload['status'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'SonicWall Firewall',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'SonicWall',
                'entPhysicalModelName' => $system['model'] ?? '',
                'entPhysicalSerialNum' => $system['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'SonicWall',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'sonicwall',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['firmware-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeSonicInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['interfaces'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['comment'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['link'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => ($iface['enable'] ?? false) ? 'up' : 'down',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

    public static function normalizeSonicSensors(array $payload): array
    {
        $sensors = [];

        if (isset($payload['connections'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'sonicwall',
                'sensor_descr' => 'Active Connections',
                'sensor_index' => 'connections',
                'sensor_current' => $payload['connections'],
                'sensor_limit' => null,
                'sensor_limit_low' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    public static function normalizeCheckpointGateways(array $payload): array
    {
        $inventory = [];
        $gateways = $payload['objects'] ?? [];

        foreach ($gateways as $idx => $gateway) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Gateway: {$gateway['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $gateway['name'] ?? "Gateway $idx",
                'entPhysicalModelName' => $gateway['hardware'] ?? '',
                'entPhysicalSerialNum' => $gateway['uid'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Check Point',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'checkpoint',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $gateway['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }

    public static function normalizeCheckpointInterfaces(array $payload): array
    {
        $ports = [];
        $interfaces = $payload['objects'] ?? [];

        foreach ($interfaces as $idx => $iface) {
            $ports[] = [
                'ifIndex' => $idx,
                'ifName' => $iface['name'] ?? "interface-$idx",
                'ifDescr' => $iface['comments'] ?? "interface-$idx",
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => ($iface['state'] ?? 'down') === 'up' ? 'up' : 'down',
                'ifAdminStatus' => 'up',
                'ifSpeed' => 0,
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['mac-address'] ?? null,
            ];
        }

        return ['ports' => $ports];
    }

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
                    'sensor_class'   => 'count',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Volume $name Size",
                    'sensor_index'   => "ontap_vol_size_$index",
                    'sensor_current' => $size,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                    'user_func'      => 'format_bytes',
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

    /**
     * Normalize vCenter VM list to get all VMs for further processing
     */
    public static function vcVmsToContext(array $payload): array
    {
        // This just passes through VM list for context
        return $payload['value'] ?? $payload;
    }

    /**
     * Normalize vCenter VM network adapters to ports with MAC/MTU
     * Input: Array of VMs with their hardware.ethernet data
     */
    public static function vcVmNetworkAdaptersToPorts(array $payload): array
    {
        $ports = [];
        $vms = $payload['value'] ?? $payload;

        foreach ($vms as $vm) {
            $vmName = $vm['name'] ?? $vm['vm'] ?? 'unknown';
            $vmId = $vm['vm'] ?? null;

            if (!$vmId) {
                continue;
            }

            // VM ethernet adapters should be in 'hardware' -> 'ethernet'
            $adapters = $vm['hardware']['ethernet'] ?? $vm['ethernet'] ?? [];

            foreach ($adapters as $adapter) {
                $nicId = $adapter['nic'] ?? null;
                if (!$nicId) {
                    continue;
                }

                $macAddress = $adapter['mac_address'] ?? '';
                $label = $adapter['label'] ?? "Network adapter";
                $state = $adapter['state'] ?? 'UNKNOWN';
                $backing = $adapter['backing'] ?? [];
                $networkName = $backing['network'] ?? 'unknown';

                // Determine interface name
                $ifName = "{$vmName}:{$label}";
                $index = self::stableIndexFromName($ifName);

                // Map state to oper status
                $ifOperStatus = match (strtoupper($state)) {
                    'CONNECTED' => 'up',
                    'DISCONNECTED' => 'down',
                    default => 'unknown',
                };

                $ports[] = [
                    'ifIndex' => $index,
                    'ifName' => $ifName,
                    'ifDescr' => "VM {$vmName} - {$label}",
                    'ifType' => 'ethernetCsmacd',
                    'ifOperStatus' => $ifOperStatus,
                    'ifAdminStatus' => ($adapter['start_connected'] ?? true) ? 'up' : 'down',
                    'ifSpeed' => 1000000000, // Assume 1Gbps for virtual adapters
                    'ifMtu' => 1500, // Virtual adapters typically use 1500
                    'ifPhysAddress' => $macAddress,
                    'ifAlias' => $networkName,
                ];
            }
        }

        return $ports;
    }

    /**
     * Normalize vCenter VM guest network interfaces to IP addresses
     * Input: Array of VMs with their guest.networking.interfaces data
     */
    public static function vcVmGuestNetworkingToIpv4(array $payload): array
    {
        $ipv4 = [];
        $vms = $payload['value'] ?? $payload;

        foreach ($vms as $vm) {
            $vmName = $vm['name'] ?? $vm['vm'] ?? 'unknown';
            $interfaces = $vm['guest']['networking']['interfaces'] ?? $vm['interfaces'] ?? [];

            foreach ($interfaces as $iface) {
                $macAddress = $iface['mac_address'] ?? '';
                $nicId = $iface['nic'] ?? '';
                $ipData = $iface['ip'] ?? [];
                $ipAddresses = $ipData['ip_addresses'] ?? [];

                foreach ($ipAddresses as $ipInfo) {
                    $ipAddress = $ipInfo['ip_address'] ?? null;
                    $prefixLength = $ipInfo['prefix_length'] ?? 24;

                    // Skip IPv6 and invalid IPs
                    if (!$ipAddress || strpos($ipAddress, ':') !== false) {
                        continue;
                    }

                    // Calculate netmask from prefix length
                    $netmask = long2ip(~((1 << (32 - $prefixLength)) - 1));

                    // Use MAC address to match with port
                    $context = $macAddress ?: $nicId;
                    $index = self::stableIndexFromName("{$vmName}:{$context}");

                    $ipv4[] = [
                        'ipv4_address' => $ipAddress,
                        'ipv4_prefixlen' => $prefixLength,
                        'ipv4_network_id' => null,
                        'context_name' => $context,
                        'port_id' => null, // Will be matched by MAC address
                        'ifIndex' => $index,
                    ];
                }
            }
        }

        return $ipv4;
    }

    /**
     * Normalize vCenter port groups to VLANs
     * Input: GET /vcenter/network
     * Extracts VLAN IDs from port group names (e.g., "5-MPS-Voice" = VLAN 5)
     */
    public static function vcPortGroupsToVlans(array $payload): array
    {
        $vlans = [];
        $portGroups = $payload['value'] ?? $payload;

        foreach ($portGroups as $pg) {
            $name = $pg['name'] ?? '';
            $type = $pg['type'] ?? '';
            $networkId = $pg['network'] ?? '';

            if (!$name) {
                continue;
            }

            // Try to extract VLAN ID from name (format: "123-Name" or "Name")
            $vlanId = null;
            if (preg_match('/^(\d+)-/', $name, $matches)) {
                $vlanId = (int)$matches[1];
            }

            // If no VLAN ID found, use a hash of the name as ID
            if ($vlanId === null) {
                $vlanId = self::stableIndexFromName($name);
            }

            $vlans[] = [
                'vlan_vlan' => $vlanId,
                'vlan_domain' => 1,
                'vlan_name' => $name,
                'vlan_type' => $type === 'DISTRIBUTED_PORTGROUP' ? 'ethernet' : 'ethernet',
                'vlan_mtu' => null,
            ];
        }

        return $vlans;
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
                    'sensor_class'   => 'count',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => 'Memory Used',
                    'sensor_index'   => "zb_mem_used_$index",
                    'sensor_current' => (int)$val,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                    'user_func'      => 'format_bytes',
                ];
            } elseif (str_contains($key, 'vfs.fs.size[/,used]')) {
                $sensors[] = [
                    'sensor_class'   => 'count',
                    'sensor_type'    => 'zabbix',
                    'sensor_descr'   => 'Root FS Used',
                    'sensor_index'   => "zb_rootfs_used_$index",
                    'sensor_current' => (int)$val,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                    'user_func'      => 'format_bytes',
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
            'healthy', 'ok', 'normal', 'ready', 'operational' => 2,
            'degraded', 'warning' => 1,
            'critical', 'failed', 'unhealthy', 'faulted' => 0,
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
        // Constrain to fit in MySQL INT(11) column (max 2,147,483,647)
        // Using 2 billion as max to leave headroom
        return abs(crc32($name)) % 2000000000;
    }

    protected static function netmaskToCidr(string $netmask): int
    {
        // Convert netmask to CIDR prefix length
        // e.g., "255.255.255.0" => 24
        $long = ip2long($netmask);
        $base = ip2long('255.255.255.255');
        return (int) (32 - log(($long ^ $base) + 1, 2));
    }

    public static function normalizeGenericHrDevice(array $payload): array
    {
        $devices = [];
        $items = $payload['items'] ?? $payload['devices'] ?? $payload;

        foreach ($items as $device) {
            $devices[] = [
                'hrDeviceIndex'    => $device['index'] ?? $device['device_index'] ?? self::stableIndexFromName($device['name'] ?? 'device'),
                'hrDeviceDescr'    => $device['descr'] ?? $device['description'] ?? $device['name'] ?? 'Unknown Device',
                'hrDeviceType'     => $device['type'] ?? $device['device_type'] ?? 'unknown',
                'hrDeviceStatus'   => $device['status'] ?? 'unknown',
                'hrDeviceErrors'   => $device['errors'] ?? $device['error_count'] ?? 0,
                'hrProcessorLoad'  => $device['processor_load'] ?? $device['cpu_load'] ?? null,
            ];
        }

        return $devices;
    }

    public static function normalizeGenericHrSystem(array $payload): array
    {
        $data = $payload['system'] ?? $payload[0] ?? $payload;

        return [
            'hrSystemNumUsers'     => $data['num_users'] ?? $data['users'] ?? $data['user_count'] ?? 0,
            'hrSystemProcesses'    => $data['processes'] ?? $data['process_count'] ?? 0,
            'hrSystemMaxProcesses' => $data['max_processes'] ?? $data['process_limit'] ?? 0,
        ];
    }

    public static function normalizeGenericIpv4Addresses(array $payload): array
    {
        $addresses = [];
        $items = $payload['items'] ?? $payload['addresses'] ?? $payload;

        foreach ($items as $addr) {
            $ifIdentifier = null;
            if (isset($addr['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $addr['ifIndex']];
            } elseif (isset($addr['ifName'])) {
                $ifIdentifier = ['ifName' => $addr['ifName']];
            } elseif (isset($addr['interface'])) {
                $ifIdentifier = ['ifName' => $addr['interface']];
            }

            if (!$ifIdentifier || !isset($addr['address']) && !isset($addr['ip'])) {
                continue;
            }

            $prefixlen = $addr['prefixlen'] ?? $addr['prefix_length'] ?? 24;
            if (isset($addr['netmask']) && !is_numeric($addr['netmask'])) {
                $prefixlen = self::netmaskToCidr($addr['netmask']);
            }

            $addresses[] = array_merge($ifIdentifier, [
                'ipv4_address'   => $addr['address'] ?? $addr['ip'],
                'ipv4_prefixlen' => $prefixlen,
                'context_name'   => $addr['context'] ?? $addr['vrf'] ?? '',
            ]);
        }

        return $addresses;
    }

    public static function normalizeGenericIpv4Mac(array $payload): array
    {
        $mappings = [];
        $items = $payload['items'] ?? $payload['arp'] ?? $payload['arp_table'] ?? $payload;

        foreach ($items as $entry) {
            $ifIdentifier = null;
            if (isset($entry['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $entry['ifIndex']];
            } elseif (isset($entry['ifName'])) {
                $ifIdentifier = ['ifName' => $entry['ifName']];
            } elseif (isset($entry['interface'])) {
                $ifIdentifier = ['ifName' => $entry['interface']];
            }

            $mac = $entry['mac'] ?? $entry['mac_address'] ?? $entry['hwaddr'] ?? null;
            $ip = $entry['ip'] ?? $entry['address'] ?? $entry['ipv4_address'] ?? null;

            if (!$ifIdentifier || !$mac || !$ip) {
                continue;
            }

            $mappings[] = array_merge($ifIdentifier, [
                'mac_address'  => $mac,
                'ipv4_address' => $ip,
                'context_name' => $entry['context'] ?? $entry['vrf'] ?? '',
            ]);
        }

        return $mappings;
    }

    public static function normalizeGenericIpv4Networks(array $payload): array
    {
        $networks = [];
        $items = $payload['items'] ?? $payload['networks'] ?? $payload['routes'] ?? $payload;

        foreach ($items as $net) {
            $network = $net['network'] ?? $net['subnet'] ?? $net['cidr'] ?? null;
            if (!$network) {
                continue;
            }

            $networks[] = [
                'ipv4_network' => $network,
                'context_name' => $net['context'] ?? $net['vrf'] ?? null,
            ];
        }

        return $networks;
    }

    public static function normalizeGenericTransceivers(array $payload): array
    {
        $transceivers = [];
        $items = $payload['items'] ?? $payload['transceivers'] ?? $payload['optics'] ?? $payload;

        foreach ($items as $idx => $trans) {
            $ifIdentifier = null;
            if (isset($trans['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $trans['ifIndex']];
            } elseif (isset($trans['ifName'])) {
                $ifIdentifier = ['ifName' => $trans['ifName']];
            } elseif (isset($trans['interface']) || isset($trans['port'])) {
                $ifIdentifier = ['ifName' => $trans['interface'] ?? $trans['port']];
            }

            if (!$ifIdentifier) {
                continue;
            }

            $transceivers[] = array_merge($ifIdentifier, [
                'index'                 => $trans['index'] ?? $idx,
                'entity_physical_index' => $trans['entity_physical_index'] ?? null,
                'type'                  => $trans['type'] ?? $trans['form_factor'] ?? null,
                'vendor'                => $trans['vendor'] ?? $trans['manufacturer'] ?? null,
                'oui'                   => $trans['oui'] ?? null,
                'model'                 => $trans['model'] ?? $trans['part_number'] ?? null,
                'revision'              => $trans['revision'] ?? null,
                'serial'                => $trans['serial'] ?? $trans['serial_number'] ?? null,
                'date'                  => $trans['date'] ?? $trans['manufacture_date'] ?? null,
                'ddm'                   => isset($trans['ddm']) ? (bool) $trans['ddm'] : null,
                'encoding'              => $trans['encoding'] ?? null,
                'cable'                 => $trans['cable'] ?? $trans['cable_type'] ?? null,
                'distance'              => $trans['distance'] ?? $trans['reach'] ?? null,
                'wavelength'            => $trans['wavelength'] ?? null,
                'connector'             => $trans['connector'] ?? $trans['connector_type'] ?? null,
                'channels'              => $trans['channels'] ?? 1,
            ]);
        }

        return $transceivers;
    }

    public static function normalizePureVolumesToStorage(array $payload): array
    {
        $rows = [];
        $list = $payload['items'] ?? $payload['records'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'volume';
            $index = self::stableIndexFromName($name);
            $size = (int) ($vol['size'] ?? $vol['provisioned'] ?? 0);
            $used = (int) ($vol['space']['total_physical'] ?? $vol['space']['used'] ?? 0);
            $free = $size > 0 ? max(0, $size - $used) : null;

            $rows[] = [
                'type'          => 'array-volume',
                'storage_index' => "pure_vol_$index",
                'storage_descr' => $name,
                'storage_size'  => $size,
                'storage_used'  => $used,
                'storage_free'  => $free,
                'storage_units' => 1, // bytes
                'storage_perc'  => $size > 0 ? round(($used / $size) * 100, 2) : null,
                'storage_perc_warn' => \LibrenmsConfig::get('storage_perc_warn', 80),
            ];
        }

        return $rows;
    }

    // Convert NetApp volumes into storage table rows
    public static function normalizeOntapVolumesToStorageDb(array $payload): array
    {
        $rows = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'volume';
            $index = self::stableIndexFromName($name);
            $size = (int) ($vol['space']['size'] ?? $vol['size'] ?? 0);
            $used = (int) ($vol['space']['used'] ?? $vol['used'] ?? 0);
            $free = $size > 0 ? max(0, $size - $used) : null;

            $rows[] = [
                'type'          => 'array-volume',
                'storage_index' => "ontap_vol_$index",
                'storage_descr' => $name,
                'storage_size'  => $size,
                'storage_used'  => $used,
                'storage_free'  => $free,
                'storage_units' => 1, // bytes
                'storage_perc'  => $size > 0 ? round(($used / $size) * 100, 2) : null,
                'storage_perc_warn' => \LibrenmsConfig::get('storage_perc_warn', 80),
            ];
        }

        return $rows;
    }
}