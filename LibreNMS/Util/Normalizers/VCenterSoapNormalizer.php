<?php

namespace LibreNMS\Util\Normalizers;

use Illuminate\Support\Facades\Log;

/**
 * Normalizer for vCenter SOAP API Performance Manager data
 * Converts SOAP PerformanceManager data into LibreNMS sensors format
 */
class VCenterSoapNormalizer
{
    /**
     * Normalize host real-time performance data from SOAP PerformanceManager
     *
     * Input format from VCenterSoapClient::fetchHostRealTimePerformance():
     * [
     *   [
     *     'host' => ['moref' => ..., 'name' => 'esxi-host-01', 'state' => 'connected', 'version' => '7.0.3'],
     *     'performance' => [
     *       'entity' => MoRef,
     *       'sampleInfo' => [...],
     *       'values' => [
     *         ['counter' => 'cpu.usage.average', 'instance' => '', 'data' => [45.2, 46.1, 44.8]],
     *         ['counter' => 'mem.usage.average', 'instance' => '', 'data' => [72.5, 73.0, 72.8]],
     *         ...
     *       ]
     *     ]
     *   ],
     *   ...
     * ]
     *
     * Output: LibreNMS sensors array
     */
    public static function normalizeHostPerformance(array $data): array
    {
        $sensors = [];

        foreach ($data as $hostData) {
            $hostName = $hostData['host']['name'] ?? 'unknown-host';
            $perfData = $hostData['performance'] ?? [];
            $values = $perfData['values'] ?? [];

            foreach ($values as $metric) {
                $counter = $metric['counter'] ?? '';
                $instance = $metric['instance'] ?? '';
                $dataPoints = $metric['data'] ?? [];

                if (empty($dataPoints) || empty($counter)) {
                    continue;
                }

                // Calculate average of samples for current value
                $currentValue = count($dataPoints) > 0 ? array_sum($dataPoints) / count($dataPoints) : 0;

                // Map counter to sensor
                $sensor = self::mapCounterToSensor($counter, $instance, $hostName, $currentValue);

                if ($sensor) {
                    $sensors[] = $sensor;
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize VM real-time performance data
     */
    public static function normalizeVMPerformance(array $data): array
    {
        $sensors = [];

        foreach ($data as $vmData) {
            $vmName = $vmData['vm']['name'] ?? 'unknown-vm';
            $perfData = $vmData['performance'] ?? [];
            $values = $perfData['values'] ?? [];

            foreach ($values as $metric) {
                $counter = $metric['counter'] ?? '';
                $instance = $metric['instance'] ?? '';
                $dataPoints = $metric['data'] ?? [];

                if (empty($dataPoints) || empty($counter)) {
                    continue;
                }

                $currentValue = count($dataPoints) > 0 ? array_sum($dataPoints) / count($dataPoints) : 0;

                $sensor = self::mapCounterToSensor($counter, $instance, "VM: $vmName", $currentValue, 'vm');

                if ($sensor) {
                    $sensors[] = $sensor;
                }
            }
        }

        return $sensors;
    }

    /**
     * Map vSphere performance counter to LibreNMS sensor format
     */
    protected static function mapCounterToSensor(string $counter, string $instance, string $entityName, float $value, string $prefix = 'host'): ?array
    {
        $sensorClass = null;
        $sensorDescr = null;
        $sensorUnit = null;
        $sensorDivisor = 1;
        $sensorMultiplier = 1;

        // Parse counter format: "group.name.rollup" (e.g., "cpu.usage.average")
        $parts = explode('.', $counter);
        if (count($parts) < 3) {
            return null;
        }

        $group = $parts[0];
        $name = $parts[1];
        $rollup = $parts[2];

        $instanceSuffix = $instance ? " ($instance)" : '';

        switch ($group) {
            case 'cpu':
                $sensorClass = 'percent';
                switch ($name) {
                    case 'usage':
                        $sensorDescr = "$entityName CPU Usage$instanceSuffix";
                        $sensorUnit = '%';
                        $value = $value / 100; // vSphere returns 4500 for 45%
                        break;
                    case 'ready':
                        $sensorClass = 'count';
                        $sensorDescr = "$entityName CPU Ready$instanceSuffix";
                        $sensorUnit = 'ms';
                        break;
                    case 'costop':
                        $sensorClass = 'count';
                        $sensorDescr = "$entityName CPU Co-Stop$instanceSuffix";
                        $sensorUnit = 'ms';
                        break;
                    case 'coreUtilization':
                        $sensorClass = 'percent';
                        $sensorDescr = "$entityName CPU Core Util$instanceSuffix";
                        $sensorUnit = '%';
                        $value = $value / 100;
                        break;
                    default:
                        return null;
                }
                break;

            case 'mem':
                switch ($name) {
                    case 'usage':
                    case 'active':
                        $sensorClass = 'percent';
                        $sensorDescr = "$entityName Memory " . ucfirst($name) . "$instanceSuffix";
                        $sensorUnit = '%';
                        $value = $value / 100;
                        break;
                    case 'consumed':
                        $sensorClass = 'load';
                        $sensorDescr = "$entityName Memory Consumed$instanceSuffix";
                        $sensorUnit = 'KB';
                        break;
                    case 'swapinRate':
                    case 'swapoutRate':
                        $sensorClass = 'load';
                        $sensorDescr = "$entityName Memory " . ($name === 'swapinRate' ? 'Swap In' : 'Swap Out') . "$instanceSuffix";
                        $sensorUnit = 'KBps';
                        break;
                    case 'vmmemctl':
                        $sensorClass = 'load';
                        $sensorDescr = "$entityName Memory Balloon$instanceSuffix";
                        $sensorUnit = 'KB';
                        break;
                    default:
                        return null;
                }
                break;

            case 'disk':
                switch ($name) {
                    case 'read':
                    case 'write':
                        $sensorClass = 'load';
                        $sensorDescr = "$entityName Disk " . ucfirst($name) . "$instanceSuffix";
                        $sensorUnit = 'KBps';
                        break;
                    case 'maxTotalLatency':
                    case 'totalLatency':
                        $sensorClass = 'delay';
                        $sensorDescr = "$entityName Disk Latency$instanceSuffix";
                        $sensorUnit = 'ms';
                        break;
                    default:
                        return null;
                }
                break;

            case 'net':
                switch ($name) {
                    case 'received':
                    case 'transmitted':
                        $sensorClass = 'load';
                        $sensorDescr = "$entityName Network " . ucfirst($name) . "$instanceSuffix";
                        $sensorUnit = 'KBps';
                        break;
                    case 'pktdropRx':
                    case 'pktdropTx':
                        $sensorClass = 'count';
                        $sensorDescr = "$entityName Network " . ($name === 'pktdropRx' ? 'RX Drops' : 'TX Drops') . "$instanceSuffix";
                        $sensorUnit = 'pkts';
                        break;
                    default:
                        return null;
                }
                break;

            default:
                return null;
        }

        if (!$sensorClass || !$sensorDescr) {
            return null;
        }

        return [
            'sensor_class' => $sensorClass,
            'sensor_type' => 'vcenter_soap',
            'sensor_descr' => $sensorDescr,
            'sensor_index' => md5($counter . $instance . $entityName),
            'sensor_current' => round($value, 2),
            'sensor_limit' => null,
            'sensor_limit_low' => null,
            'entPhysicalIndex' => null,
            'entPhysicalIndex_measured' => null,
            'user_func' => null,
            'rrd_type' => 'GAUGE',
            'poller_type' => 'rest',
        ];
    }

    /**
     * Normalize VCSA appliance health data from REST API
     *
     * Input: Response from /api/appliance/health/system
     */
    public static function normalizeVcsaHealthSystem(array $data): array
    {
        $sensors = [];

        // Overall system health
        if (isset($data['value'])) {
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'vcsa_health',
                'sensor_descr' => 'VCSA System Health',
                'sensor_index' => 'system_health',
                'sensor_current' => self::mapHealthState($data['value']),
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'rrd_type' => 'GAUGE',
                'poller_type' => 'rest',
            ];
        }

        return $sensors;
    }

    /**
     * Normalize VCSA appliance load/CPU data
     */
    public static function normalizeVcsaHealthLoad(array $data): array
    {
        $sensors = [];

        if (isset($data['value'])) {
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'vcsa_health',
                'sensor_descr' => 'VCSA Load Health',
                'sensor_index' => 'load_health',
                'sensor_current' => self::mapHealthState($data['value']),
                'rrd_type' => 'GAUGE',
                'poller_type' => 'rest',
            ];
        }

        return $sensors;
    }

    /**
     * Normalize VCSA database health
     */
    public static function normalizeVcsaHealthDatabase(array $data): array
    {
        $sensors = [];

        if (isset($data['value'])) {
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'vcsa_health',
                'sensor_descr' => 'VCSA Database Health',
                'sensor_index' => 'database_health',
                'sensor_current' => self::mapHealthState($data['value']),
                'rrd_type' => 'GAUGE',
                'poller_type' => 'rest',
            ];
        }

        return $sensors;
    }

    /**
     * Normalize VCSA storage health
     */
    public static function normalizeVcsaHealthStorage(array $data): array
    {
        $sensors = [];

        if (isset($data['value'])) {
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'vcsa_health',
                'sensor_descr' => 'VCSA Storage Health',
                'sensor_index' => 'storage_health',
                'sensor_current' => self::mapHealthState($data['value']),
                'rrd_type' => 'GAUGE',
                'poller_type' => 'rest',
            ];
        }

        return $sensors;
    }

    /**
     * Normalize VCSA appliance metrics from /api/appliance/monitoring/metrics
     *
     * Input format:
     * {
     *   "value": [
     *     {
     *       "name": "system.cpu.util",
     *       "data": [
     *         {"timestamp": "2025-12-10T...", "value": 12.5},
     *         ...
     *       ]
     *     },
     *     ...
     *   ]
     * }
     */
    public static function normalizeVcsaMetrics(array $data): array
    {
        $sensors = [];

        if (!isset($data['value']) || !is_array($data['value'])) {
            return $sensors;
        }

        foreach ($data['value'] as $metric) {
            $metricName = $metric['name'] ?? '';
            $metricData = $metric['data'] ?? [];

            if (empty($metricData) || empty($metricName)) {
                continue;
            }

            // Get the latest data point
            $latest = end($metricData);
            $value = $latest['value'] ?? 0;

            $sensor = self::mapVcsaMetricToSensor($metricName, $value);
            if ($sensor) {
                $sensors[] = $sensor;
            }
        }

        return $sensors;
    }

    /**
     * Map VCSA metric names to sensors
     */
    protected static function mapVcsaMetricToSensor(string $metricName, float $value): ?array
    {
        $mapping = [
            'system.cpu.util' => ['class' => 'percent', 'descr' => 'VCSA CPU Utilization', 'unit' => '%'],
            'system.mem.util' => ['class' => 'percent', 'descr' => 'VCSA Memory Utilization', 'unit' => '%'],
            'system.swap.util' => ['class' => 'percent', 'descr' => 'VCSA Swap Utilization', 'unit' => '%'],
            'system.disk.util' => ['class' => 'percent', 'descr' => 'VCSA Disk Utilization', 'unit' => '%'],
            'system.net.rx' => ['class' => 'load', 'descr' => 'VCSA Network RX', 'unit' => 'bytes'],
            'system.net.tx' => ['class' => 'load', 'descr' => 'VCSA Network TX', 'unit' => 'bytes'],
        ];

        if (!isset($mapping[$metricName])) {
            // Handle partition-specific disk metrics (e.g., system.disk.part0.util)
            if (preg_match('/^system\.disk\.part(\d+)\.util$/', $metricName, $matches)) {
                $partNum = $matches[1];
                return [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'vcsa_appliance',
                    'sensor_descr' => "VCSA Disk Partition $partNum Utilization",
                    'sensor_index' => md5($metricName),
                    'sensor_current' => round($value, 2),
                    'rrd_type' => 'GAUGE',
                    'poller_type' => 'rest',
                ];
            }

            return null;
        }

        $meta = $mapping[$metricName];

        return [
            'sensor_class' => $meta['class'],
            'sensor_type' => 'vcsa_appliance',
            'sensor_descr' => $meta['descr'],
            'sensor_index' => md5($metricName),
            'sensor_current' => round($value, 2),
            'rrd_type' => 'GAUGE',
            'poller_type' => 'rest',
        ];
    }

    /**
     * Map VMware health state to numeric value
     */
    protected static function mapHealthState(string $state): int
    {
        $stateMap = [
            'green' => 0,    // OK
            'yellow' => 1,   // Warning
            'orange' => 2,   // Degraded
            'red' => 3,      // Critical
            'gray' => 4,     // Unknown
        ];

        return $stateMap[strtolower($state)] ?? 4;
    }

    /**
     * Normalize vCenter appliance network interfaces from SOAP
     *
     * Input format from VCenterSoapClient::fetchPorts():
     * [
     *   [
     *     'ifIndex' => 1,
     *     'ifName' => 'Network adapter 1',
     *     'ifDescr' => 'vCenter Network adapter 1 (Network: VM Network)',
     *     'ifType' => 'ethernetCsmacd',
     *     'ifSpeed' => 1000000000,
     *     'ifPhysAddress' => '00:50:56:xx:xx:xx',
     *     'ifOperStatus' => 'up',
     *     'ifAdminStatus' => 'up',
     *     'ifMtu' => 1500,
     *     '_key' => 4000,
     *   ],
     *   ...
     * ]
     *
     * Output: LibreNMS ports array
     */
    public static function normalizePorts(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $ports = [];
        foreach ($data as $port) {
            $ports[] = [
                'ifIndex' => $port['ifIndex'] ?? 0,
                'ifName' => $port['ifName'] ?? '',
                'ifDescr' => $port['ifDescr'] ?? '',
                'ifType' => $port['ifType'] ?? 'ethernetCsmacd',
                'ifSpeed' => $port['ifSpeed'] ?? 0,
                'ifPhysAddress' => $port['ifPhysAddress'] ?? '',
                'ifOperStatus' => $port['ifOperStatus'] ?? 'unknown',
                'ifAdminStatus' => $port['ifAdminStatus'] ?? 'up',
                'ifMtu' => $port['ifMtu'] ?? 1500,
            ];
        }

        return $ports;
    }

    /**
     * Normalize vCenter cluster metrics from SOAP
     *
     * Input format from VCenterSoapClient::fetchClusters():
     * [
     *   [
     *     'cluster_name' => 'Production Cluster',
     *     'num_hosts' => 5,
     *     'num_effective_hosts' => 5,
     *     'num_vms_total' => 120,
     *     'num_vms_powered_on' => 115,
     *     'total_cpu_mhz' => 100000,
     *     'effective_cpu_mhz' => 80000,
     *     'cpu_usage_pct' => 20.5,
     *     'total_memory_mb' => 524288,
     *     'effective_memory_mb' => 450000,
     *     'memory_usage_pct' => 14.2,
     *   ],
     *   ...
     * ]
     *
     * Output: Array suitable for DeviceApiPersistor::saveClusters()
     */
    public static function normalizeClusters(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $clusters = [];
        foreach ($data as $cluster) {
            $clusters[] = [
                'cluster_name' => $cluster['cluster_name'] ?? 'Unknown',
                'cluster_type' => 'vmware',
                'cluster_level' => 'cluster',
                'num_hosts' => $cluster['num_hosts'] ?? 0,
                'num_effective_hosts' => $cluster['num_effective_hosts'] ?? 0,
                'num_vms_total' => $cluster['num_vms_total'] ?? 0,
                'num_vms_powered_on' => $cluster['num_vms_powered_on'] ?? 0,
                'total_cpu_mhz' => $cluster['total_cpu_mhz'] ?? 0,
                'effective_cpu_mhz' => $cluster['effective_cpu_mhz'] ?? 0,
                'cpu_usage_pct' => $cluster['cpu_usage_pct'] ?? 0,
                'total_memory_mb' => $cluster['total_memory_mb'] ?? 0,
                'effective_memory_mb' => $cluster['effective_memory_mb'] ?? 0,
                'memory_usage_pct' => $cluster['memory_usage_pct'] ?? 0,
            ];
        }

        return $clusters;
    }

    /**
     * Normalize vCenter VM snapshots from SOAP
     *
     * Input format from VCenterSoapClient::fetchVMSnapshots():
     * [
     *   [
     *     'vm_name' => 'web-server-01',
     *     'power_state' => 'poweredOn',
     *     'snapshot_count' => 3,
     *     'snapshots' => [
     *       ['name' => 'Before Upgrade', 'create_time' => '2025-12-01T10:00:00Z'],
     *       ['name' => 'Post Patching', 'create_time' => '2025-12-10T15:30:00Z'],
     *       ...
     *     ]
     *   ],
     *   ...
     * ]
     *
     * Output: Array suitable for DeviceApiPersistor::saveVMSnapshots()
     */
    public static function normalizeVMSnapshots(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $vmSnapshots = [];
        foreach ($data as $vm) {
            $snapshotDetails = [];
            $oldestDate = null;

            if (isset($vm['snapshots']) && is_array($vm['snapshots'])) {
                foreach ($vm['snapshots'] as $snapshot) {
                    $createTime = $snapshot['create_time'] ?? null;
                    $snapshotDetails[] = [
                        'name' => $snapshot['name'] ?? 'Unnamed',
                        'create_time' => $createTime,
                    ];

                    // Track oldest snapshot
                    if ($createTime) {
                        $timestamp = strtotime($createTime);
                        if ($oldestDate === null || $timestamp < $oldestDate) {
                            $oldestDate = $timestamp;
                        }
                    }
                }
            }

            $vmSnapshots[] = [
                'vm_name' => $vm['vm_name'] ?? 'Unknown',
                'power_state' => $vm['power_state'] ?? 'unknown',
                'snapshot_count' => $vm['snapshot_count'] ?? 0,
                'snapshot_details' => json_encode($snapshotDetails),
                'oldest_snapshot_date' => $oldestDate ? date('Y-m-d H:i:s', $oldestDate) : null,
            ];
        }

        return $vmSnapshots;
    }

    /**
     * Normalize vCenter appliance network statistics from SOAP
     *
     * Input format from VCenterSoapClient::fetchPortsStatistics():
     * [
     *   [
     *     'ifIndex' => 1,
     *     'instance' => '4000',
     *     'ifInOctets_rate' => 12345,
     *     'ifOutOctets_rate' => 67890,
     *     'ifInUcastPkts_rate' => 123,
     *     'ifOutUcastPkts_rate' => 456,
     *     'ifInErrors_rate' => 0,
     *     'ifOutErrors_rate' => 0,
     *   ],
     *   ...
     * ]
     *
     * Output: LibreNMS port statistics array
     */
    public static function normalizePortsStatistics(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $statistics = [];
        foreach ($data as $stat) {
            $statistics[] = [
                'ifIndex' => $stat['ifIndex'] ?? 0,
                'ifInOctets_rate' => $stat['ifInOctets_rate'] ?? 0,
                'ifOutOctets_rate' => $stat['ifOutOctets_rate'] ?? 0,
                'ifInUcastPkts_rate' => $stat['ifInUcastPkts_rate'] ?? 0,
                'ifOutUcastPkts_rate' => $stat['ifOutUcastPkts_rate'] ?? 0,
                'ifInErrors_rate' => $stat['ifInErrors_rate'] ?? 0,
                'ifOutErrors_rate' => $stat['ifOutErrors_rate'] ?? 0,
            ];
        }

        return $statistics;
    }
}
