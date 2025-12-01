<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;

/**
 * ESXi SOAP API Response Normalizer
 *
 * Transforms vSphere SOAP API responses into LibreNMS data structures
 */
class EsxiSoapNormalizer
{
    /**
     * Normalize ESXi hardware info to device_info structure
     *
     * @param Device $device
     * @param array $payload Hardware data from EsxiSoapClient::fetchHostHardware()
     * @return array Device info for DeviceApiPersistor
     */
    public static function normalizeHardware(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $deviceInfo = [
            'hardware' => $payload['model'] ?? null,
            'serial' => $payload['serial'] ?? null,
            'version' => $payload['version'] ?? null,
            'features' => $payload['full_name'] ?? null,
        ];

        // Add system name if available
        if (!empty($payload['hostname'])) {
            $deviceInfo['sysName'] = $payload['hostname'];
            // If domain is also available, create FQDN
            if (!empty($payload['domain'])) {
                $deviceInfo['sysName'] = $payload['hostname'] . '.' . $payload['domain'];
            }
        }

        return [$deviceInfo];
    }

    /**
     * Normalize ESXi network interfaces to ports structure
     *
     * @param Device $device
     * @param array $payload Network interfaces from EsxiSoapClient::fetchNetworkInterfaces()
     * @return array Ports for DeviceApiPersistor::savePorts()
     */
    public static function normalizeNetworkInterfaces(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $ports = [];
        foreach ($payload as $interface) {
            $ports[] = [
                'ifIndex' => $interface['ifIndex'] ?? 0,
                'ifName' => $interface['ifName'] ?? '',
                'ifDescr' => $interface['ifDescr'] ?? '',
                'ifType' => $interface['ifType'] ?? 'ethernetCsmacd',
                'ifSpeed' => $interface['ifSpeed'] ?? 0,
                'ifPhysAddress' => $interface['ifPhysAddress'] ?? '',
                'ifOperStatus' => $interface['ifOperStatus'] ?? 'unknown',
                'ifAdminStatus' => $interface['ifAdminStatus'] ?? 'up',
                'ifMtu' => $interface['ifMtu'] ?? 1500,
            ];
        }

        return $ports;
    }

    /**
     * Normalize ESXi performance metrics to sensors
     *
     * @param Device $device
     * @param array $payload Performance metrics from EsxiSoapClient::fetchHostPerformance()
     * @return array Sensors for DeviceApiPersistor::saveSensors()
     */
    public static function normalizePerformance(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $sensors = [];

        // CPU usage sensor
        if (isset($payload['cpu_usage_percent'])) {
            $sensors[] = [
                'sensor_class' => 'load',
                'sensor_type' => 'esxi-soap',
                'sensor_descr' => 'CPU Usage',
                'sensor_index' => 'cpu_usage',
                'sensor_current' => round($payload['cpu_usage_percent'], 2),
                'sensor_limit' => 100,
                'sensor_limit_low' => 0,
                'rrd_type' => 'GAUGE',
            ];
        }

        // Memory usage sensor
        if (isset($payload['memory_usage_percent'])) {
            $sensors[] = [
                'sensor_class' => 'load',
                'sensor_type' => 'esxi-soap',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'memory_usage',
                'sensor_current' => round($payload['memory_usage_percent'], 2),
                'sensor_limit' => 100,
                'sensor_limit_low' => 0,
                'rrd_type' => 'GAUGE',
            ];
        }

        // Note: Uptime is handled by LibreNMS core polling, not as a sensor

        return $sensors;
    }

    /**
     * Normalize ESXi performance to processors structure
     *
     * @param Device $device
     * @param array $payload Performance metrics from EsxiSoapClient::fetchHostPerformance()
     * @return array Processors for DeviceApiPersistor::saveProcessors()
     */
    public static function normalizeProcessors(Device $device, array $payload): array
    {
        if (empty($payload) || !isset($payload['cpu_usage_percent'])) {
            return [];
        }

        return [[
            'processor_descr' => 'ESXi Host CPU',
            'processor_index' => 0,
            'processor_type' => 'esxi-soap',
            'processor_usage' => round($payload['cpu_usage_percent'], 2),
        ]];
    }

    /**
     * Normalize ESXi performance to mempools structure
     *
     * @param Device $device
     * @param array $payload Performance metrics from EsxiSoapClient::fetchHostPerformance()
     * @return array Mempools for DeviceApiPersistor::saveMempools()
     */
    public static function normalizeMempools(Device $device, array $payload): array
    {
        if (empty($payload) || !isset($payload['memory_total_bytes'], $payload['memory_usage_bytes'])) {
            return [];
        }

        $total = $payload['memory_total_bytes'];
        $used = $payload['memory_usage_bytes'];
        $free = $total - $used;

        return [[
            'mempool_descr' => 'ESXi Host Memory',
            'mempool_index' => 0,
            'mempool_type' => 'esxi-soap',
            'mempool_total' => $total,
            'mempool_used' => $used,
            'mempool_free' => $free,
            'mempool_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
        ]];
    }

    /**
     * Normalize ESXi datastores to storage structure
     *
     * @param Device $device
     * @param array $payload Datastores from EsxiSoapClient::fetchDatastores()
     * @return array Storage for DeviceApiPersistor::saveStorage()
     */
    public static function normalizeDatastores(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $storage = [];
        foreach ($payload as $idx => $datastore) {
            $total = $datastore['capacity_bytes'] ?? 0;
            $free = $datastore['free_bytes'] ?? 0;
            $used = $total - $free;

            $storage[] = [
                'storage_mib' => $datastore['name'] ?? "datastore{$idx}",
                'storage_descr' => $datastore['name'] ?? "Datastore {$idx}",
                'storage_type' => $datastore['type'] ?? 'vmfs',
                'storage_index' => $idx,
                'storage_size' => $total,
                'storage_used' => $used,
                'storage_free' => $free,
                'storage_units' => 1, // bytes
                'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
            ];
        }

        return $storage;
    }

    /**
     * Normalize ESXi network statistics to ports_statistics structure
     *
     * ESXi SOAP API returns rates (KBps), not cumulative counters.
     * We pass through the _rate fields for GAUGE RRD storage.
     *
     * @param Device $device
     * @param array $payload Network statistics from EsxiSoapClient::fetchNetworkStatistics()
     * @return array Port statistics for DeviceApiPersistor::savePortsStatistics()
     */
    public static function normalizeNetworkStatistics(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $statistics = [];
        foreach ($payload as $stat) {
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

    /**
     * Normalize ESXi hardware to inventory structure
     *
     * @param Device $device
     * @param array $payload Hardware data from EsxiSoapClient::fetchHostHardware()
     * @return array Inventory for DeviceApiPersistor::saveInventory()
     */
    public static function normalizeInventory(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $inventory = [];

        // Chassis entry
        $inventory[] = [
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => $payload['full_name'] ?? 'ESXi Host',
            'entPhysicalClass' => 'chassis',
            'entPhysicalName' => $payload['model'] ?? 'Unknown',
            'entPhysicalModelName' => $payload['model'] ?? null,
            'entPhysicalSerialNum' => $payload['serial'] ?? null,
            'entPhysicalMfgName' => $payload['vendor'] ?? null,
            'entPhysicalHardwareRev' => $payload['version'] ?? null,
        ];

        // CPU entries
        if (isset($payload['cpu_count'])) {
            for ($i = 0; $i < $payload['cpu_count']; $i++) {
                $inventory[] = [
                    'entPhysicalIndex' => 100 + $i,
                    'entPhysicalDescr' => "CPU {$i}",
                    'entPhysicalClass' => 'cpu',
                    'entPhysicalName' => "Processor {$i}",
                    'entPhysicalContainedIn' => 1, // Parent is chassis
                ];
            }
        }

        // Memory entry
        if (isset($payload['memory_bytes'])) {
            $memoryGB = round($payload['memory_bytes'] / (1024 * 1024 * 1024), 2);
            $inventory[] = [
                'entPhysicalIndex' => 200,
                'entPhysicalDescr' => "System Memory ({$memoryGB} GB)",
                'entPhysicalClass' => 'memory',
                'entPhysicalName' => 'RAM',
                'entPhysicalContainedIn' => 1,
            ];
        }

        return $inventory;
    }

    /**
     * Normalize ESXi VLANs to vlans structure
     *
     * @param Device $device
     * @param array $payload VLANs from EsxiSoapClient::fetchVlans()
     * @return array VLANs for DeviceApiPersistor::saveVlans()
     */
    public static function normalizeVlans(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $vlans = [];
        foreach ($payload as $vlan) {
            $vlans[] = [
                'vlan_vlan' => $vlan['vlan_vlan'] ?? 0,
                'vlan_domain' => $vlan['vlan_domain'] ?? 1,
                'vlan_name' => $vlan['vlan_name'] ?? '',
                'vlan_type' => $vlan['vlan_type'] ?? 'ethernet',
                'vlan_mtu' => $vlan['vlan_mtu'] ?? null,
            ];
        }

        return $vlans;
    }

    /**
     * Normalize ESXi IPv4 addresses to ipv4_addresses structure
     *
     * @param Device $device
     * @param array $payload IPv4 addresses from EsxiSoapClient::fetchIpv4Addresses()
     * @return array IPv4 addresses for DeviceApiPersistor::saveIpv4Addresses()
     */
    public static function normalizeIpv4Addresses(Device $device, array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $addresses = [];
        foreach ($payload as $addr) {
            $addresses[] = [
                'ifIndex' => $addr['ifIndex'] ?? 0,
                'ifName' => $addr['ifName'] ?? '',
                'ipv4_address' => $addr['ipv4_address'] ?? '',
                'ipv4_prefixlen' => $addr['ipv4_prefixlen'] ?? 24,
                'context_name' => $addr['context_name'] ?? '',
            ];
        }

        return $addresses;
    }

    /**
     * Normalize VM data from ESXi SOAP API
     *
     * @param Device $device
     * @param array $payload
     * @return array
     */
    public static function normalizeVms(Device $device, array $payload): array
    {
        // The payload should be an array of VMs returned by EsxiSoapClient::fetchVms()
        // Each VM should already be in the correct format with vm_type, vmwVmVMID, etc.
        // We just need to ensure the data is in the right structure
        return $payload;
    }
}
