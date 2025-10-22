<?php

namespace App\RestApi\Vendors\Mappers;

use App\RestApi\Vendors\VendorMapperInterface;

/**
 * PureStorageMapper
 *
 * Vendor mapper for Pure Storage FlashArray REST API 2.26
 * Maps API response paths directly to database table.field structure
 */
class PureStorageMapper implements VendorMapperInterface
{
    public function getVendorName(): string
    {
        return 'Pure Storage';
    }

    public function getOsPatterns(): array
    {
        return [
            'Purity//FA*',
            'Purity*',
            'Pure Storage*',
        ];
    }

    public function getDescription(): string
    {
        return 'Pure Storage FlashArray REST API 2.26 - Direct mapping to database fields';
    }

    public function getVersion(): string
    {
        return '2.26.0';
    }

    /**
     * Get all endpoint configurations
     * Format: endpoint => array of table configs
     */
    public function getMappings(): array
    {
        return [
            '/arrays' => $this->getArrayConfig(),
            '/network-interfaces' => $this->getNetworkInterfacesConfig(),
            '/network-interfaces/performance' => $this->getNetworkPerformanceConfig(),
            '/volumes' => $this->getVolumesConfig(),
            '/drives' => $this->getDrivesConfig(),
            '/arrays/performance' => $this->getArrayPerformanceConfig(),
            '/volumes/performance' => $this->getVolumePerformanceConfig(),
            '/controllers' => $this->getControllersConfig(),
            '/hardware' => $this->getHardwareConfig(),
            '/network-interfaces/port-details' => $this->getPortDetailsConfig(),
            '/array-connections' => $this->getArrayConnectionsConfig(),
            '/subnets' => $this->getSubnetsConfig(),
            '/space' => $this->getSpaceConfig(),
        ];
    }

    public function getMappingsForEndpoint(string $endpoint): array
    {
        $allMappings = $this->getMappings();
        return $allMappings[$endpoint] ?? [];
    }

    public function getTargetTableForEndpoint(string $endpoint): string
    {
        $config = $this->getMappingsForEndpoint($endpoint);
        return $config['target_table'] ?? 'custom';
    }

    public function transformValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    public function isValidMapping(string $endpoint, string $field, string $targetTable): bool
    {
        return true;
    }

    public function getSensorClass(string $endpoint, string $sensorDescr): ?string
    {
        $sensorClasses = [
            '/arrays/performance' => [
                'read_bytes_per_sec' => 'bitrate',
                'write_bytes_per_sec' => 'bitrate',
                'reads_per_sec' => 'count',
                'writes_per_sec' => 'count',
                'usec_per_read_op' => 'delay',
                'usec_per_write_op' => 'delay',
                'queue_usec_per_read_op' => 'delay',
                'queue_usec_per_write_op' => 'delay',
                'bytes_per_read' => 'count',
                'bytes_per_write' => 'count',
            ],
            '/volumes/performance' => [
                'read_bytes_per_sec' => 'bitrate',
                'write_bytes_per_sec' => 'bitrate',
                'reads_per_sec' => 'count',
                'writes_per_sec' => 'count',
                'usec_per_read_op' => 'delay',
                'usec_per_write_op' => 'delay',
                'queue_usec_per_read_op' => 'delay',
                'queue_usec_per_write_op' => 'delay',
            ],
            '/hardware' => [
                'temperature' => 'temperature',
                'voltage' => 'voltage',
                'status' => 'state',
            ],
            '/network-interfaces/port-details' => [
                'temperature' => 'temperature',
                'voltage' => 'voltage',
                'tx_bias' => 'current',
                'tx_power' => 'power',
                'rx_power' => 'power',
                'tx_fault' => 'state',
                'rx_los' => 'state',
            ],
            '/controllers' => [
                'status' => 'state',
            ],
        ];

        if (isset($sensorClasses[$endpoint][$sensorDescr])) {
            return $sensorClasses[$endpoint][$sensorDescr];
        }

        return 'gauge';
    }

    public function getSensorDescription(string $endpoint, string $apiField): string
    {
        return $apiField;
    }

    // =====================================================================
    // ENDPOINT CONFIGURATIONS
    // =====================================================================

    /**
     * /arrays endpoint - Device information
     * Response: {'items': [{'name': '...', 'version': '...', ...}]}
     */
    private function getArrayConfig(): array
    {
        return [
            'target_table' => 'devices',
            'item_identifier' => null,  // Single device, use first item
            'fields' => [
                'devices.hostname' => '$.items[0].name',
                'devices.sysName' => '$.items[0].name',
                'devices.version' => '$.items[0].version',
                'devices.os' => '$.items[0].os',
                'devices.hardware' => '$.items[0].model',
                'devices.serial' => '$.items[0].id',
            ]
        ];
    }

    /**
     * /network-interfaces endpoint - Network interface configuration
     * Response: {'items': [{'name': 'ct0.eth0', ...}, {'name': 'ct0.eth1', ...}]}
     */
    private function getNetworkInterfacesConfig(): array
    {
        return [
            'target_table' => 'ports',
            'item_identifier' => '$.items[*].name',  // Use interface name to group data
            'fields' => [
                'ports.ifName' => '$.name',
                'ports.ifDescr' => '$.services[0]',
                'ports.ifType' => '$.interface_type',
                'ports.ifSpeed' => '$.speed',
                'ports.ifPhysAddress' => '$.eth.mac_address',
                'ports.ifAdminStatus' => '$.enabled',
                'ports.ifOperStatus' => '$.enabled',
                'ports.ifMtu' => '$.eth.mtu',
                'ports.ifAlias' => '$.eth.address',
                'ports.ifVlan' => '$.eth.vlan',
            ]
        ];
    }

    /**
     * /network-interfaces/performance endpoint - Port performance metrics
     * Response: {'items': [{'eth': {'received_bytes_per_sec': 123, ...}}, ...]}
     * These must be linked to ports by interface name
     */
    private function getNetworkPerformanceConfig(): array
    {
        return [
            'target_table' => 'ports',
            'item_identifier' => '$.items[*].name',  // Link by interface name
            'fields' => [
                'ports.ifInOctets' => '$.eth.received_bytes_per_sec',
                'ports.ifOutOctets' => '$.eth.transmitted_bytes_per_sec',
                'ports.ifInUcastPkts' => '$.eth.received_packets_per_sec',
                'ports.ifOutUcastPkts' => '$.eth.transmitted_packets_per_sec',
                'ports.ifInErrors' => '$.eth.rx_errors_per_sec',
                'ports.ifOutErrors' => '$.eth.tx_errors_per_sec',
            ]
        ];
    }

    /**
     * /volumes endpoint - Storage volumes
     * Response: {'items': [{'name': 'vol1', ...}, {'name': 'vol2', ...}]}
     */
    private function getVolumesConfig(): array
    {
        return [
            'target_table' => 'storage',
            'item_identifier' => '$.items[*].name',  // Use volume name
            'fields' => [
                'storage.storage_descr' => '$.name',
                'storage.storage_type' => "'pure-volume'",  // Static value
                'storage.storage_size' => '$.space.total_provisioned',
                'storage.storage_used' => '$.space.total_physical',
                'storage.data_reduction_ratio' => '$.space.data_reduction',
                'storage.total_reduction_ratio' => '$.space.total_reduction',
                'storage.snapshots_bytes' => '$.space.snapshots',
            ]
        ];
    }

    /**
     * /drives endpoint - Physical drives
     * Response: {'items': [{'name': 'CH0.BAY0', ...}, ...]}
     */
    private function getDrivesConfig(): array
    {
        return [
            'target_table' => 'storage',
            'item_identifier' => '$.items[*].name',  // Use drive name
            'fields' => [
                'storage.storage_descr' => '$.name',
                'storage.storage_type' => "'pure-drive'",  // Static value
                'storage.storage_size' => '$.capacity',
            ]
        ];
    }

    /**
     * /arrays/performance endpoint - Array-level performance metrics
     * Response: {'items': [{'read_bytes_per_sec': 123, 'write_bytes_per_sec': 456, ...}]}
     * Single item - create array-level sensors
     */
    private function getArrayPerformanceConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => null,  // Single array, no grouping needed
            'sensor_prefix' => 'array',  // Prefix for sensor names
            'fields' => [
                'sensors.read_throughput' => '$.items[0].read_bytes_per_sec',
                'sensors.write_throughput' => '$.items[0].write_bytes_per_sec',
                'sensors.reads_per_sec' => '$.items[0].reads_per_sec',
                'sensors.writes_per_sec' => '$.items[0].writes_per_sec',
                'sensors.read_latency' => '$.items[0].usec_per_read_op',
                'sensors.write_latency' => '$.items[0].usec_per_write_op',
                'sensors.read_queue_latency' => '$.items[0].queue_usec_per_read_op',
                'sensors.write_queue_latency' => '$.items[0].queue_usec_per_write_op',
                'sensors.bytes_per_read' => '$.items[0].bytes_per_read',
                'sensors.bytes_per_write' => '$.items[0].bytes_per_write',
            ]
        ];
    }

    /**
     * /volumes/performance endpoint - Per-volume performance metrics
     * Response: {'items': [{'name': 'vol1', 'read_bytes_per_sec': 123, ...}, ...]}
     * Multiple items - create per-volume sensors, linked by volume name
     */
    private function getVolumePerformanceConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => '$.items[*].name',  // Link by volume name
            'sensor_prefix' => 'volume',  // Prefix for sensor names
            'fields' => [
                'sensors.read_throughput' => '$.read_bytes_per_sec',
                'sensors.write_throughput' => '$.write_bytes_per_sec',
                'sensors.reads_per_sec' => '$.reads_per_sec',
                'sensors.writes_per_sec' => '$.writes_per_sec',
                'sensors.read_latency' => '$.usec_per_read_op',
                'sensors.write_latency' => '$.usec_per_write_op',
                'sensors.read_queue_latency' => '$.queue_usec_per_read_op',
                'sensors.write_queue_latency' => '$.queue_usec_per_write_op',
            ]
        ];
    }

    /**
     * /controllers endpoint - Controller status
     * Response: {'items': [{'name': 'CT0', ...}, {'name': 'CT1', ...}]}
     */
    private function getControllersConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => '$.items[*].name',  // Link by controller name
            'sensor_prefix' => 'controller',
            'fields' => [
                'sensors.status' => '$.status',
            ]
        ];
    }

    /**
     * /hardware endpoint - Hardware components (PSU, fans, etc)
     * Response: {'items': [{'name': 'PSU0', 'temperature': 45, ...}, ...]}
     */
    private function getHardwareConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => '$.items[*].name',  // Link by component name
            'sensor_prefix' => 'hardware',
            'fields' => [
                'sensors.temperature' => '$.temperature',
                'sensors.voltage' => '$.voltage',
                'sensors.status' => '$.status',
            ]
        ];
    }

    /**
     * /network-interfaces/port-details endpoint - Transceiver details
     * Response: {'items': [{'name': 'ct0.eth0', 'temperature': [{'measurement': 45}], ...}, ...]}
     * Link to ports by interface name
     */
    private function getPortDetailsConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => '$.items[*].name',  // Link by port name
            'sensor_prefix' => 'transceiver',
            'fields' => [
                'sensors.temperature' => '$.temperature[0].measurement',
                'sensors.voltage' => '$.voltage[0].measurement',
                'sensors.tx_bias' => '$.tx_bias[0].measurement',
                'sensors.tx_power' => '$.tx_power[0].measurement',
                'sensors.rx_power' => '$.rx_power[0].measurement',
                'sensors.tx_fault' => '$.tx_fault[0].flag',
                'sensors.rx_los' => '$.rx_los[0].flag',
            ]
        ];
    }

    /**
     * /array-connections endpoint - Replication links
     * Response: {'items': [{'name': 'remote-array1', 'local_port': 'eth0', ...}, ...]}
     */
    private function getArrayConnectionsConfig(): array
    {
        return [
            'target_table' => 'links',
            'item_identifier' => '$.items[*].name',  // Link by remote array name
            'fields' => [
                'links.local_port' => '$.local_port',
                'links.remote_port' => '$.remote_port',
                'links.remote_hostname' => '$.name',
                'links.link_transport' => '$.replication_transport',
                'links.link_status' => '$.status',
            ]
        ];
    }

    /**
     * /subnets endpoint - Network configuration
     * Response: {'items': [{'name': 'default', 'prefix': '10.0.0.0/24', ...}, ...]}
     */
    private function getSubnetsConfig(): array
    {
        return [
            'target_table' => 'custom',  // Subnets are not standard tables
            'item_identifier' => '$.items[*].name',
            'fields' => [
                'ipv4_network' => '$.prefix',
                'vlan_id' => '$.vlan',
                'vlan_name' => '$.name',
            ]
        ];
    }

    /**
     * /space endpoint - Array space information
     * Response: {'total_provisioned': 1000000, 'total_used': 500000, ...}
     * No items array - single response object
     */
    private function getSpaceConfig(): array
    {
        return [
            'target_table' => 'sensors',
            'item_identifier' => null,  // Single response, no items
            'sensor_prefix' => 'space',
            'fields' => [
                'sensors.total_provisioned' => '$.total_provisioned',
                'sensors.total_used' => '$.total_used',
                'sensors.data_reduction' => '$.data_reduction',
                'sensors.total_reduction' => '$.total_reduction',
                'sensors.replication' => '$.replication',
                'sensors.snapshots' => '$.snapshots',
            ]
        ];
    }
}
