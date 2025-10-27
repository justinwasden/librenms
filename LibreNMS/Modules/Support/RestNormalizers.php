<?php

namespace LibreNMS\Modules\Support;

class RestNormalizers
{
    // Existing Pure normalizers (as provided)
    public static function normalizePureArraySensors(array $arrayPayload, array $perfPayload): array { /* ... keep your existing implementation ... */ }
    public static function normalizePureHardware(array $payload): array { /* ... existing ... */ }
    public static function normalizePureNetworkInterfaces(array $payload): array { /* ... existing ... */ }
    public static function normalizePureNetworkPerformance(array $payload, int $pollIntervalSec): array { /* ... existing ... */ }
    public static function normalizePurePortOptics(array $payload): array { /* ... existing ... */ }
    public static function normalizePureVolumes(array $volumesPayload, array $volPerfPayload = []): array { /* ... existing ... */ }
    public static function normalizePureHosts(array $payload): array { /* ... existing ... */ }
    public static function normalizeProxmoxNodeStatus(array $payload): array { /* ... existing ... */ }
    public static function normalizeProxmoxNodeNetwork(array $payload): array { /* ... existing ... */ }
    public static function normalizeProxmoxNodeStorage(array $payload): array { /* ... existing ... */ }
    public static function normalizeProxmoxClusterStatus(array $payload): array { /* ... existing ... */ }
    public static function normalizeProxmoxClusterResources(array $payload): array { /* ... existing ... */ }

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

    // Helpers (keep your existing implementations)
    protected static function pureStatusToNumeric(string $status): int { /* existing */ }
    protected static function mapPureHardwareType(string $type): string { /* existing */ }
    protected static function toStatus($v): string { /* existing */ }
    protected static function stableIndexFromName(string $name): int { /* existing */ }
}