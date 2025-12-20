<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use RuntimeException;

class EsxiClient implements DeviceApiClientInterface
{
    protected Device $device;
    protected EsxiSoapClient $soapClient;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Initialize the helper SOAP client
        // This handles the complex XML/SOAP logic required for ESXi performance counters
        $this->soapClient = new EsxiSoapClient($device);
    }

    public function supports(Device $device): bool
    {
        // Supports 'vmware' if it's not a vCenter, or specifically 'esxi'
        return in_array($device->os, ['vmware', 'esxi']) && $device->sysObjectId !== '1.3.6.1.4.1.6876.4.1'; // Exclude vCenter OID if known
    }

		public function capabilities(): array
    {
        return ['device_info', 'processors', 'mempools', 'sensors', 'inventory', 'vlans'];
    }

    public function isReachable(): bool
    {
        return $this->soapClient->login();
    }

    public function getApiInfo(): array
    {
        return ['vendor' => 'vmware', 'type' => 'esxi'];
    }

    public function fetchDeviceInfo(Device $device): array
    {
        // Retrieve static hardware info via SOAP
        $hw = $this->soapClient->fetchHostHardware();

        return [
            'version' => $hw['version'] ?? null,
            'hardware' => $hw['model'] ?? 'VMware ESXi Host',
            'serial' => $hw['uuid'] ?? null,
            'sysName' => $hw['name'] ?? null,
        ];
    }

    public function fetchProcessors(Device $device): array
    {
        // Get Real-time CPU usage
        $perf = $this->soapClient->fetchHostPerformance();
        $hw = $this->soapClient->fetchHostHardware();

        if (isset($perf['cpu_usage_percent'])) {
            return [[
                'processor_index' => 1,
                'processor_type' => 'esxi-cpu',
                'processor_descr' => 'ESXi CPU Aggregate',
                'processor_usage' => $perf['cpu_usage_percent'], // 0-100 float
                'processor_oid' => '.1.3.6.1.4.1.6876.4.1', // Fake OID for internal tracking
            ]];
        }

        return [];
    }

    public function fetchMempools(Device $device): array
    {
        $perf = $this->soapClient->fetchHostPerformance();

        if (isset($perf['memory_usage_bytes']) && isset($perf['memory_total_bytes'])) {
            $used = $perf['memory_usage_bytes'];
            $total = $perf['memory_total_bytes'];
            $perc = ($total > 0) ? ($used / $total) * 100 : 0;

            return [[
                'mempool_index' => 1,
                'mempool_type' => 'esxi-mem',
                'mempool_descr' => 'Physical Memory',
                'mempool_total' => $total,
                'mempool_used' => $used,
                'mempool_free' => $total - $used,
                'mempool_perc' => $perc
            ]];
        }

        return [];
    }

    public function fetchSensors(Device $device): array
    {
        $sensors = [];
        $health = $this->soapClient->fetchHealthStatus();

        foreach ($health as $idx => $h) {
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'esxi-health',
                'sensor_descr' => $h['name'],
                'sensor_index' => 'health-' . $idx,
                'sensor_current' => $h['status_code'], // 1=Good, 2=Warn, 3=Crit
                'states' => [
                    ['value' => 1, 'descr' => 'Green', 'generic' => 0, 'graph' => 1],
                    ['value' => 2, 'descr' => 'Yellow', 'generic' => 1, 'graph' => 1],
                    ['value' => 3, 'descr' => 'Red', 'generic' => 2, 'graph' => 1],
                ]
            ];
        }

        return $sensors;
    }

    public function fetchInventory(Device $device): array
    {
        // Return physical chassis info
        $hw = $this->soapClient->fetchHostHardware();
        return [[
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => $hw['model'] ?? 'ESXi Chassis',
            'entPhysicalClass' => 'chassis',
            'entPhysicalName'  => $hw['name'] ?? 'Chassis',
            'entPhysicalSerialNum' => $hw['uuid'] ?? '',
            'entPhysicalVendorType' => 'vmware-esxi',
            'entPhysicalIsFRU' => 1,
        ]];
    }

		public function fetchVlans(Device $device): array
    {
        // Use SOAP client to get actual Port Group config including VLAN IDs
        $portGroups = $this->soapClient->fetchPortGroups();

        $vlans = [];
        foreach ($portGroups as $pg) {
            $vlans[] = [
                'vlan_vlan'  => (int)$pg['vlanId'], // Maps 0 or 4095 correctly
                'vlan_descr' => $pg['name'],
                'vlan_type'  => $pg['switchName'] ? "vSwitch: {$pg['switchName']}" : 'static',
                'vlan_mtu'   => 1500,
            ];
        }

        return $vlans;
    }

    // Unused
    public function fetchPorts(Device $device): array { return []; }
    public function fetchStorage(Device $device): array { return []; }
    public function fetchTransceivers(Device $device): array { return []; }
    public function fetchIpv4Addresses(Device $device): array { return []; }
    public function fetchPortsStatistics(Device $device): array { return []; }
    public function fetchClusters(Device $device): array { return []; }
    public function fetchHosts(Device $device): array { return []; }
    public function fetchVms(Device $device): array { return []; }
}