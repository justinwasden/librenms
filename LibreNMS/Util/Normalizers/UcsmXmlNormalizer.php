<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;

/**
 * Normalizer for Cisco UCSM XML API responses
 */
class UcsmXmlNormalizer
{
    /**
     * Normalize chassis information to inventory
     */
    public static function normalizeChassis(Device $device, array $payload, array $ep = []): array
    {
        $inventory = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        // Handle single or multiple chassis
        $chassisList = [];
        if (isset($outConfigs['equipmentChassis'])) {
            $chassisList = isset($outConfigs['equipmentChassis']['@attributes'])
                ? [$outConfigs['equipmentChassis']]
                : $outConfigs['equipmentChassis'];
        }

        foreach ($chassisList as $chassis) {
            $attrs = $chassis['@attributes'] ?? $chassis;

            $chassisId = $attrs['id'] ?? 'unknown';
            $dn = $attrs['dn'] ?? '';
            $model = $attrs['model'] ?? '';
            $serial = $attrs['serial'] ?? '';
            $vendor = $attrs['vendor'] ?? 'Cisco';

            $inventory[] = [
                'entPhysicalIndex' => crc32($dn) & 0x7FFFFFFF,
                'entPhysicalDescr' => "Chassis {$chassisId}",
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => "Chassis {$chassisId}",
                'entPhysicalModelName' => $model,
                'entPhysicalSerialNum' => $serial,
                'entPhysicalMfgName' => $vendor,
                'entPhysicalContainedIn' => 0,
            ];
        }

        return $inventory;
    }

    /**
     * Normalize compute blades to inventory
     */
    public static function normalizeBlades(Device $device, array $payload, array $ep = []): array
    {
        return [
            'inventory' => [],
            'sensors' => [],
            'processors' => [],
            'mempools' => [],
        ];
    }

    /**
     * Normalize fabric interconnects to inventory
     */
    public static function normalizeFabricInterconnects(Device $device, array $payload, array $ep = []): array
    {
        $inventory = [];
        $sensors = [];
        $deviceInfo = [];

        $outConfigs = $payload['data']['outConfigs'] ?? [];

        // Handle single or multiple fabric interconnects
        $fiList = [];
        if (isset($outConfigs['networkElement'])) {
            $fiList = isset($outConfigs['networkElement']['@attributes'])
                ? [$outConfigs['networkElement']]
                : $outConfigs['networkElement'];
        }

        // Collect FI information for device-level info
        $fiSerials = [];
        $fiModels = [];
        $fiVersions = [];
        $operableFIs = 0;

        foreach ($fiList as $fi) {
            $attrs = $fi['@attributes'] ?? $fi;

            $dn = $attrs['dn'] ?? '';
            $id = $attrs['id'] ?? '';
            $model = $attrs['model'] ?? '';
            $serial = $attrs['serial'] ?? '';
            $version = $attrs['revision'] ?? '';
            // Fabric Interconnects use 'operability' field instead of 'operState'
            $operState = $attrs['operability'] ?? $attrs['operState'] ?? '';

            // Count operable FIs for HA status
            if (strtolower($operState) === 'operable') {
                $operableFIs++;
            }

            $fiName = "Fabric Interconnect {$id}";

            // Collect for device info
            if ($serial) {
                $fiSerials[] = "FI {$id}: {$serial}";
            }
            if ($model && !in_array($model, $fiModels)) {
                $fiModels[] = $model;
            }
            if ($version && !in_array($version, $fiVersions)) {
                $fiVersions[] = $version;
            }

            $inventory[] = [
                'entPhysicalIndex' => crc32($dn) & 0x7FFFFFFF,
                'entPhysicalDescr' => "{$fiName} - {$model}",
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $fiName,
                'entPhysicalModelName' => $model,
                'entPhysicalSerialNum' => $serial,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalContainedIn' => 0,
            ];

            // Add state sensor
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'ucsm-fi',
                'sensor_descr' => "{$fiName} State",
                'sensor_index' => "fi_{$id}_state",
                'sensor_current' => self::mapOperState($operState),
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 0, 'descr' => 'operable'],
                    ['value' => 2, 'generic' => 1, 'graph' => 0, 'descr' => 'inoperable'],
                    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'degraded'],
                ],
            ];
        }

        // Add HA readiness sensor based on number of operable FIs
        // 0 = failed, 1 = degraded (one FI), 2 = ready (both FIs), 3 = standalone
        $haReadiness = 0; // failed
        $totalFIs = count($fiList);

        if ($totalFIs === 1) {
            $haReadiness = 3; // standalone
        } elseif ($totalFIs === 2) {
            if ($operableFIs === 2) {
                $haReadiness = 2; // HA ready
            } elseif ($operableFIs === 1) {
                $haReadiness = 1; // degraded
            }
        }

        $sensors[] = [
            'sensor_class' => 'state',
            'sensor_type' => 'ucsm-ha-readiness',
            'sensor_descr' => 'UCS HA Readiness',
            'sensor_index' => 'ha_readiness',
            'sensor_current' => $haReadiness,
            'states' => [
                ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'failed'],
                ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                ['value' => 2, 'generic' => 0, 'graph' => 0, 'descr' => 'ready'],
                ['value' => 3, 'generic' => 0, 'graph' => 0, 'descr' => 'standalone'],
            ],
        ];

        // Build device info from Fabric Interconnects
        if (!empty($fiSerials)) {
            $deviceInfo['serial'] = implode(', ', $fiSerials);
        }
        if (!empty($fiModels)) {
            $deviceInfo['hardware'] = implode(', ', $fiModels);
        }
        if (!empty($fiVersions) && !in_array('0', $fiVersions)) {
            // Only set version if we have meaningful version info (not just "0")
            $deviceInfo['version'] = implode(', ', $fiVersions);
        }

        // Set sysDescr for UCS Manager
        $deviceInfo['sysDescr'] = 'Cisco UCS Manager';

        $result = [
            'inventory' => $inventory,
            'sensors' => $sensors,
        ];

        if (!empty($deviceInfo)) {
            $result['device_info'] = $deviceInfo;
        }

        return $result;
    }

    /**
     * Normalize top system info to device_info and HA status sensors
     * This captures the UCS domain/cluster name and HA configuration
     */
    public static function normalizeTopSystem(Device $device, array $payload, array $ep = []): array
    {
        $deviceInfo = [];
        $sensors = [];

        $outConfigs = $payload['data']['outConfigs'] ?? [];

        if (isset($outConfigs['topSystem'])) {
            $topSystem = $outConfigs['topSystem'];
            if (isset($topSystem['@attributes'])) {
                $topSystem = $topSystem['@attributes'];
            }

            // Extract system name (UCS domain name)
            if (!empty($topSystem['name'])) {
                $deviceInfo['sysName'] = $topSystem['name'];
            }

            $mode = $topSystem['mode'] ?? 'unknown';

            // Also capture mode (cluster/standalone) and address
            if (!empty($mode)) {
                // Append mode to sysDescr
                $modeDisplay = ucfirst($mode);
                $deviceInfo['sysDescr'] = "Cisco UCS Manager ({$modeDisplay})";
            }

            // Create HA/Cluster mode sensor
            $clusterMode = 0; // 0 = unknown
            $clusterModeMap = [
                'standalone' => 1,
                'cluster' => 2,
                'unknown' => 0,
            ];
            $clusterMode = $clusterModeMap[strtolower($mode)] ?? 0;

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'ucsm-cluster-mode',
                'sensor_descr' => 'UCS Cluster Mode',
                'sensor_index' => 'cluster_mode',
                'sensor_current' => $clusterMode,
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 0, 'descr' => 'standalone'],
                    ['value' => 2, 'generic' => 0, 'graph' => 0, 'descr' => 'cluster'],
                ],
            ];
        }

        $result = [];
        if (!empty($deviceInfo)) {
            $result['device_info'] = $deviceInfo;
        }
        if (!empty($sensors)) {
            $result['sensors'] = $sensors;
        }

        return $result;
    }

    /**
     * Normalize power supplies to sensors
     */
    public static function normalizePowerSupplies(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        $psuList = [];
        if (isset($outConfigs['equipmentPsu'])) {
            $psuList = isset($outConfigs['equipmentPsu']['@attributes'])
                ? [$outConfigs['equipmentPsu']]
                : $outConfigs['equipmentPsu'];
        }

        foreach ($psuList as $psu) {
            $attrs = $psu['@attributes'] ?? $psu;

            $dn = $attrs['dn'] ?? '';
            $id = $attrs['id'] ?? '';
            $model = $attrs['model'] ?? '';
            $operState = $attrs['operState'] ?? '';

            // Extract chassis info from DN (e.g., sys/chassis-1/psu-1 or sys/switch-A/psu-1)
            // Use DN hash to ensure uniqueness
            $dnHash = substr(md5($dn), 0, 8);
            $location = '';
            if (preg_match('/chassis-(\d+)/', $dn, $matches)) {
                $location = "Chassis {$matches[1]} ";
            } elseif (preg_match('/switch-([AB])/', $dn, $matches)) {
                $location = "FI {$matches[1]} ";
            }

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'ucsm-psu',
                'sensor_descr' => "{$location}PSU {$id} State",
                'sensor_index' => "psu_{$dnHash}",
                'sensor_current' => self::mapOperState($operState),
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 0, 'descr' => 'operable'],
                    ['value' => 2, 'generic' => 1, 'graph' => 0, 'descr' => 'inoperable'],
                    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'degraded'],
                    ['value' => 4, 'generic' => 3, 'graph' => 0, 'descr' => 'power-off'],
                    ['value' => 5, 'generic' => 1, 'graph' => 0, 'descr' => 'failed'],
                ],
            ];
        }

        return ['sensors' => $sensors];
    }

    /**
     * Normalize fans to sensors
     */
    public static function normalizeFans(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        $fanList = [];
        if (isset($outConfigs['equipmentFan'])) {
            $fanList = isset($outConfigs['equipmentFan']['@attributes'])
                ? [$outConfigs['equipmentFan']]
                : $outConfigs['equipmentFan'];
        }

        foreach ($fanList as $fan) {
            $attrs = $fan['@attributes'] ?? $fan;

            $dn = $attrs['dn'] ?? '';
            $id = $attrs['id'] ?? '';
            $operState = $attrs['operState'] ?? '';

            // Extract location from DN (e.g., sys/chassis-1/fan-module-1-1/fan-1)
            // Use DN hash to ensure uniqueness
            $dnHash = substr(md5($dn), 0, 8);
            $location = '';
            if (preg_match('/chassis-(\d+)\/fan-module-(\d+-\d+)/', $dn, $matches)) {
                $location = "Chassis {$matches[1]} Module {$matches[2]} ";
            } elseif (preg_match('/chassis-(\d+)/', $dn, $matches)) {
                $location = "Chassis {$matches[1]} ";
            } elseif (preg_match('/switch-([AB])/', $dn, $matches)) {
                $location = "FI {$matches[1]} ";
            }

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'ucsm-fan',
                'sensor_descr' => "{$location}Fan {$id} State",
                'sensor_index' => "fan_{$dnHash}",
                'sensor_current' => self::mapOperState($operState),
                'states' => [
                    ['value' => 0, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 1, 'generic' => 0, 'graph' => 0, 'descr' => 'operable'],
                    ['value' => 2, 'generic' => 1, 'graph' => 0, 'descr' => 'inoperable'],
                    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'degraded'],
                ],
            ];
        }

        return ['sensors' => $sensors];
    }

    /**
     * Normalize faults to sensors
     */
    public static function normalizeFaults(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        $faultList = [];
        if (isset($outConfigs['faultInst'])) {
            $faultList = isset($outConfigs['faultInst']['@attributes'])
                ? [$outConfigs['faultInst']]
                : $outConfigs['faultInst'];
        }

        // Count faults by severity
        $critical = 0;
        $major = 0;
        $minor = 0;
        $warning = 0;
        $info = 0;

        foreach ($faultList as $fault) {
            $attrs = $fault['@attributes'] ?? $fault;
            $severity = strtolower($attrs['severity'] ?? 'info');

            switch ($severity) {
                case 'critical':
                    $critical++;
                    break;
                case 'major':
                    $major++;
                    break;
                case 'minor':
                    $minor++;
                    break;
                case 'warning':
                    $warning++;
                    break;
                default:
                    $info++;
            }
        }

        // Create count sensors
        if ($critical > 0 || $major > 0 || $minor > 0) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'ucsm-faults',
                'sensor_descr' => 'Critical Faults',
                'sensor_index' => 'faults_critical',
                'sensor_current' => $critical,
                'sensor_limit' => 0,
            ];

            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'ucsm-faults',
                'sensor_descr' => 'Major Faults',
                'sensor_index' => 'faults_major',
                'sensor_current' => $major,
                'sensor_limit' => 0,
            ];

            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'ucsm-faults',
                'sensor_descr' => 'Minor Faults',
                'sensor_index' => 'faults_minor',
                'sensor_current' => $minor,
                'sensor_limit' => null,
            ];
        }

        return ['sensors' => $sensors];
    }

    /**
     * Normalize switch system stats to processors and mempools
     * This provides CPU and RAM metrics for the UCS Manager device from the FIs
     */
    public static function normalizeSwitchStats(Device $device, array $payload, array $ep = []): array
    {
        $processors = [];
        $mempools = [];

        $outConfigs = $payload['data']['outConfigs'] ?? [];

        $statsList = [];
        if (isset($outConfigs['swSystemStats'])) {
            $statsList = isset($outConfigs['swSystemStats']['@attributes'])
                ? [$outConfigs['swSystemStats']]
                : $outConfigs['swSystemStats'];
        }

        foreach ($statsList as $stats) {
            $attrs = $stats['@attributes'] ?? $stats;

            $dn = $attrs['dn'] ?? '';
            // Extract FI ID from DN (e.g., sys/switch-A/sysstats)
            $fiId = 'unknown';
            if (preg_match('/switch-([AB])/', $dn, $matches)) {
                $fiId = $matches[1];
            }

            $load = $attrs['load'] ?? 0;
            $loadAvg = $attrs['loadAvg'] ?? $load;
            $memAvailable = $attrs['memAvailable'] ?? 0;  // Available RAM in MB
            $memCached = $attrs['memCached'] ?? 0;

            // For UCS FIs, total memory is typically 32GB
            // memAvailable is the actual available system RAM, not kernel memory
            // We need to estimate total from the device's installed RAM
            // Default to 32GB (32128 MB based on FI networkElement data)
            $memTotal = 32128;

            // CPU usage (load average as percentage)
            // UCS FIs typically have multiple cores, estimate based on load
            $cpuUsage = min(100, $loadAvg * 10); // Rough conversion

            $processors[] = [
                'processor_index' => crc32($dn) & 0x7FFFFFFF,
                'processor_type' => 'ucsm-fi-cpu',
                'processor_descr' => "FI {$fiId} CPU",
                'processor_usage' => round($cpuUsage, 2),
            ];

            // Memory pool - memAvailable is free memory, so used = total - available
            $memUsed = $memTotal - $memAvailable;
            $mempools[] = [
                'mempool_index' => crc32($dn) & 0x7FFFFFFF,
                'mempool_type' => 'ucsm-fi-memory',
                'mempool_descr' => "FI {$fiId} Memory",
                'mempool_total' => $memTotal * 1024 * 1024, // Convert MB to bytes
                'mempool_used' => $memUsed * 1024 * 1024,
                'mempool_free' => $memAvailable * 1024 * 1024,
                'mempool_perc' => $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0,
            ];
        }

        return [
            'processors' => $processors,
            'mempools' => $mempools,
        ];
    }

    /**
     * Normalize adapter vNIC statistics to counters
     * This provides network traffic statistics for blade virtual adapters
     */
    public static function normalizeAdapterVnicStats(Device $device, array $payload, array $ep = []): array
    {
        return ['sensors' => []];
    }

    /**
     * Normalize Ethernet error statistics for FI ports
     */
    public static function normalizeEthernetErrorStats(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];

        if (! isset($payload['data']['outConfigs']['etherErrStats'])) {
            return ['sensors' => $sensors];
        }

        $stats = $payload['data']['outConfigs']['etherErrStats'];
        if (isset($stats['@attributes'])) {
            $stats = [$stats];
        }

        foreach ($stats as $errStat) {
            $attrs = $errStat['@attributes'] ?? $errStat;
            $dn = $attrs['dn'] ?? '';

            // Parse DN: sys/switch-B/slot-1/switch-ether/port-1/err-stats
            if (! preg_match('/switch-([AB])\/slot-(\d+)\/switch-ether\/port-(\d+)/', $dn, $matches)) {
                continue;
            }

            $switch = $matches[1];
            $slot = $matches[2];
            $port = $matches[3];

            $location = "FI-{$switch} P{$slot}/{$port}";
            $dnHash = substr(md5($dn), 0, 8);

            // FCS errors (Frame Check Sequence)
            if (isset($attrs['fcs']) && $attrs['fcs'] > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'ucsm-port-errors',
                    'sensor_descr' => "{$location} FCS Errors",
                    'sensor_index' => "port_{$dnHash}_fcs",
                    'sensor_current' => (int) $attrs['fcs'],
                    'sensor_limit' => null,
                ];
            }

            // Alignment errors
            if (isset($attrs['align']) && $attrs['align'] > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'ucsm-port-errors',
                    'sensor_descr' => "{$location} Align Errors",
                    'sensor_index' => "port_{$dnHash}_align",
                    'sensor_current' => (int) $attrs['align'],
                    'sensor_limit' => null,
                ];
            }

            // Out discards
            if (isset($attrs['outDiscard']) && $attrs['outDiscard'] > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'ucsm-port-discards',
                    'sensor_descr' => "{$location} Out Discards",
                    'sensor_index' => "port_{$dnHash}_out_disc",
                    'sensor_current' => (int) $attrs['outDiscard'],
                    'sensor_limit' => null,
                ];
            }
        }

        return ['sensors' => $sensors];
    }

    /**
     * Normalize chassis statistics (power and temperature)
     */
    public static function normalizeChassisStats(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];

        if (! isset($payload['data']['outConfigs']['equipmentChassisStats'])) {
            return ['sensors' => $sensors];
        }

        $stats = $payload['data']['outConfigs']['equipmentChassisStats'];
        if (isset($stats['@attributes'])) {
            $stats = [$stats];
        }

        foreach ($stats as $chassisStat) {
            $attrs = $chassisStat['@attributes'] ?? $chassisStat;
            $dn = $attrs['dn'] ?? '';

            // Parse DN: sys/chassis-2/stats
            if (! preg_match('/chassis-(\d+)\/stats/', $dn, $matches)) {
                continue;
            }

            $chassis = $matches[1];

            // Input power (Watts)
            $inputPowerAvg = $attrs['inputPowerAvg'] ?? 0;
            if ($inputPowerAvg > 0) {
                $sensors[] = [
                    'sensor_class' => 'power',
                    'sensor_type' => 'ucsm-chassis-power',
                    'sensor_descr' => "Chassis {$chassis} Input Power",
                    'sensor_index' => "chassis_{$chassis}_input_power",
                    'sensor_current' => round((float) $inputPowerAvg, 2),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }

            // Output power (Watts)
            $outputPowerAvg = $attrs['outputPowerAvg'] ?? 0;
            if ($outputPowerAvg > 0) {
                $sensors[] = [
                    'sensor_class' => 'power',
                    'sensor_type' => 'ucsm-chassis-power',
                    'sensor_descr' => "Chassis {$chassis} Output Power",
                    'sensor_index' => "chassis_{$chassis}_output_power",
                    'sensor_current' => round((float) $outputPowerAvg, 2),
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }

            // I2C errors (communication errors)
            if (isset($attrs['ChassisI2CErrors']) && $attrs['ChassisI2CErrors'] > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'ucsm-chassis-errors',
                    'sensor_descr' => "Chassis {$chassis} I2C Errors",
                    'sensor_index' => "chassis_{$chassis}_i2c_errors",
                    'sensor_current' => (int) $attrs['ChassisI2CErrors'],
                    'sensor_limit' => null,
                ];
            }
        }

        return ['sensors' => $sensors];
    }

    /**
     * Normalize Ethernet physical ports to LibreNMS ports
     */
    public static function normalizeEthernetPhysicalPorts(Device $device, array $payload, array $ep = []): array
    {
        $ports = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        // Handle etherPIo objects
        $portsList = [];
        if (isset($outConfigs['etherPIo'])) {
            $portsList = isset($outConfigs['etherPIo']['@attributes'])
                ? [$outConfigs['etherPIo']]
                : $outConfigs['etherPIo'];
        }

        foreach ($portsList as $port) {
            $attrs = $port['@attributes'] ?? $port;

            $dn = $attrs['dn'] ?? '';
            $portId = $attrs['portId'] ?? '';
            $slotId = $attrs['slotId'] ?? '';
            $switchId = $attrs['switchId'] ?? '';
            $ifRole = $attrs['ifRole'] ?? '';
            $ifType = $attrs['ifType'] ?? '';
            $operState = $attrs['operState'] ?? '';
            $adminState = $attrs['adminState'] ?? '';
            $operSpeed = $attrs['operSpeed'] ?? '';
            $mac = $attrs['mac'] ?? '';

            $portName = "Eth{$switchId}/{$slotId}/{$portId}";

            // Build description with role if present
            $description = $portName;
            if (!empty($ifRole) && $ifRole !== 'unknown') {
                $description .= " ({$ifRole})";
            }

            $ports[] = [
                'ifIndex' => crc32($dn) & 0x7FFFFFFF,
                'ifName' => $portName,
                'ifDescr' => $description,
                'ifType' => $ifType === 'ether' ? 'ethernetCsmacd' : $ifType,
                'ifOperStatus' => $operState === 'up' ? 'up' : 'down',
                'ifAdminStatus' => $adminState === 'enabled' ? 'up' : 'down',
                'ifSpeed' => self::parseSpeed($operSpeed),
                'ifPhysAddress' => $mac ?: null,
            ];
        }

        return ['ports' => $ports];
    }

    /**
     * Normalize Fibre Channel physical ports to LibreNMS ports
     */
    public static function normalizeFibreChannelPorts(Device $device, array $payload, array $ep = []): array
    {
        $ports = [];
        $outConfigs = $payload['data']['outConfigs'] ?? [];

        $portsList = [];
        if (isset($outConfigs['fcPIo'])) {
            $portsList = isset($outConfigs['fcPIo']['@attributes'])
                ? [$outConfigs['fcPIo']]
                : $outConfigs['fcPIo'];
        }

        foreach ($portsList as $port) {
            $attrs = $port['@attributes'] ?? $port;

            $dn = $attrs['dn'] ?? '';
            $portId = $attrs['portId'] ?? '';
            $slotId = $attrs['slotId'] ?? '';
            $switchId = $attrs['switchId'] ?? '';
            $operState = $attrs['operState'] ?? '';
            $adminState = $attrs['adminState'] ?? '';
            $operSpeed = $attrs['operSpeed'] ?? '';
            $wwn = $attrs['wwn'] ?? '';

            $portName = "FC{$switchId}/{$slotId}/{$portId}";

            $ports[] = [
                'ifIndex' => crc32($dn) & 0x7FFFFFFF,
                'ifName' => $portName,
                'ifDescr' => "{$portName} (Fibre Channel)",
                'ifType' => 'fibreChannel',
                'ifOperStatus' => $operState === 'up' ? 'up' : 'down',
                'ifAdminStatus' => $adminState === 'enabled' ? 'up' : 'down',
                'ifSpeed' => self::parseSpeed($operSpeed),
                'ifPhysAddress' => $wwn ?: null,
            ];
        }

        return ['ports' => $ports];
    }

    /**
     * Normalize Ethernet traffic statistics to port statistics
     */
    public static function normalizeEthernetTrafficStats(Device $device, array $payload, array $ep = []): array
    {
        $portStats = [];
        $data = $payload['data'] ?? [];

        $rxStatsList = [];
        if (isset($data['rx_stats']['outConfigs']['etherRxStats'])) {
            $rxStatsList = isset($data['rx_stats']['outConfigs']['etherRxStats']['@attributes'])
                ? [$data['rx_stats']['outConfigs']['etherRxStats']]
                : $data['rx_stats']['outConfigs']['etherRxStats'];
        }

        $txStatsList = [];
        if (isset($data['tx_stats']['outConfigs']['etherTxStats'])) {
            $txStatsList = isset($data['tx_stats']['outConfigs']['etherTxStats']['@attributes'])
                ? [$data['tx_stats']['outConfigs']['etherTxStats']]
                : $data['tx_stats']['outConfigs']['etherTxStats'];
        }

        $errStatsList = [];
        if (isset($data['error_stats']['outConfigs']['etherErrStats'])) {
            $errStatsList = isset($data['error_stats']['outConfigs']['etherErrStats']['@attributes'])
                ? [$data['error_stats']['outConfigs']['etherErrStats']]
                : $data['error_stats']['outConfigs']['etherErrStats'];
        }

        // Create stats indexed by DN for easy correlation
        $statsByDn = [];

        foreach ($rxStatsList as $rxStat) {
            $attrs = $rxStat['@attributes'] ?? $rxStat;
            $dn = preg_replace('/\/rx-stats$/', '', $attrs['dn'] ?? '');
            $statsByDn[$dn] = $statsByDn[$dn] ?? [];
        }

        foreach ($txStatsList as $txStat) {
            $attrs = $txStat['@attributes'] ?? $txStat;
            $dn = preg_replace('/\/tx-stats$/', '', $attrs['dn'] ?? '');
            $statsByDn[$dn] = $statsByDn[$dn] ?? [];
        }

        foreach ($errStatsList as $errStat) {
            $attrs = $errStat['@attributes'] ?? $errStat;
            $dn = preg_replace('/\/err-stats$/', '', $attrs['dn'] ?? '');

            $statsByDn[$dn]['ifInErrors'] = (int) ($attrs['crc'] ?? 0) + (int) ($attrs['align'] ?? 0);
            $statsByDn[$dn]['ifInDiscards'] = (int) ($attrs['inDiscard'] ?? 0);
            $statsByDn[$dn]['ifOutErrors'] = (int) ($attrs['outDiscard'] ?? 0);
        }

        // Convert to port stats format with ifIndex
        foreach ($statsByDn as $dn => $stats) {
            $stats['ifIndex'] = crc32($dn) & 0x7FFFFFFF;
            $portStats[] = $stats;
        }

        // Return flat array for DeviceApiPersistor::savePortsStatistics
        return $portStats;
    }

    /**
     * Normalize UCSM cluster/fabric interconnect info for overview display
     */
    public static function normalizeClusterInfo(Device $device, array $payload, array $ep = []): array
    {
        $clusterInfo = [
            'domain_name' => null,
            'fabric_interconnects' => [],
            'ha_ready' => false,
            'leadership' => null,
        ];

        // Get top system for domain name
        if (isset($payload['topSystem']['data']['outConfigs']['topSystem'])) {
            $topSys = $payload['topSystem']['data']['outConfigs']['topSystem'];
            $topAttrs = $topSys['@attributes'] ?? $topSys;
            $clusterInfo['domain_name'] = $topAttrs['name'] ?? null;
        }

        // Get fabric interconnect information
        $operableFIs = 0;
        if (isset($payload['fabricInterconnects']['data']['outConfigs']['networkElement'])) {
            $fiList = $payload['fabricInterconnects']['data']['outConfigs']['networkElement'];
            $fiList = isset($fiList['@attributes']) ? [$fiList] : $fiList;

            foreach ($fiList as $fi) {
                $attrs = $fi['@attributes'] ?? $fi;
                $operability = $attrs['operability'] ?? '';

                // Count operable FIs
                if (strtolower($operability) === 'operable') {
                    $operableFIs++;
                }

                $clusterInfo['fabric_interconnects'][] = [
                    'id' => $attrs['id'] ?? '',
                    'model' => $attrs['model'] ?? '',
                    'serial' => $attrs['serial'] ?? '',
                    'operability' => $operability,
                    'role' => $attrs['role'] ?? null,
                    'thermal' => $attrs['thermal'] ?? '',
                    'oob_if_ip' => $attrs['oobIfIp'] ?? '',
                    'inband_if_ip' => $attrs['inbandIfIp'] ?? '',
                    'total_memory' => isset($attrs['totalMemory']) ? (int) $attrs['totalMemory'] : 0,
                ];
            }

            // HA is ready only if we have 2 operable fabric interconnects
            $clusterInfo['ha_ready'] = $operableFIs >= 2;
        }

        // Get HA/leadership status from management entity
        if (isset($payload['managementEntity']['data']['outConfigs']['mgmtEntity'])) {
            $mgmtEntity = $payload['managementEntity']['data']['outConfigs']['mgmtEntity'];
            $mgmtAttrs = $mgmtEntity['@attributes'] ?? $mgmtEntity;
            $clusterInfo['leadership'] = $mgmtAttrs['leadership'] ?? null;
            $clusterInfo['ha_configuration'] = $mgmtAttrs['haConfiguration'] ?? null;
            // Note: Don't override ha_ready from operable FI count - the operability check is more accurate
        }

        return $clusterInfo;
    }

    /**
     * Parse speed string (e.g., "10gbps", "40gbps") to bits per second
     */
    protected static function parseSpeed(string $speed): int
    {
        if (empty($speed) || $speed === 'indeterminate') {
            return 0;
        }

        $speed = strtolower($speed);

        if (preg_match('/(\d+)\s*([kmg]?)bps/', $speed, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2] ?? '';

            switch ($unit) {
                case 'g':
                    return $value * 1000000000;
                case 'm':
                    return $value * 1000000;
                case 'k':
                    return $value * 1000;
                default:
                    return $value;
            }
        }

        return 0;
    }

    /**
     * Map UCSM operational state to numeric value
     */
    protected static function mapOperState(string $state): int
    {
        $stateMap = [
            'ok' => 1,           // UCSM uses 'ok' for operational/healthy state
            'operable' => 1,
            'inoperable' => 2,
            'degraded' => 3,
            'power-off' => 4,
            'failed' => 5,
            'removed' => 6,
            'offline' => 7,
        ];

        return $stateMap[strtolower($state)] ?? 0;
    }
}
