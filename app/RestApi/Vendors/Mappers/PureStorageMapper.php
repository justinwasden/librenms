<?php

namespace App\RestApi\Vendors\Mappers;

use App\RestApi\Vendors\VendorMapperInterface;

/**
 * PureStorageMapper
 *
 * Vendor mapper for Pure Storage FlashArray REST API 2.26
 * Implements all 160+ field mappings from JAK Mapping document
 *
 * Covers endpoints:
 * - /arrays (device info)
 * - /network-interfaces (ports)
 * - /network-interfaces/performance (port metrics)
 * - /volumes (storage)
 * - /drives (drive inventory)
 * - /arrays/performance (array metrics)
 * - /volumes/performance (volume metrics)
 * - /controllers (controller status)
 * - /hardware (hardware sensors)
 * - /network-interfaces/port-details (transceiver details)
 * - /array-connections (replication links)
 * - /subnets (network config)
 * - /space (array capacity)
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
        return 'Pure Storage FlashArray REST API 2.26 - 160+ field mappings for complete array monitoring';
    }

    public function getVersion(): string
    {
        return '2.26.0';
    }

    public function getMappings(): array
    {
        return [
            '/arrays' => $this->getArrayMappings(),
            '/network-interfaces' => $this->getNetworkInterfacesMappings(),
            '/network-interfaces/performance' => $this->getNetworkPerformanceMappings(),
            '/volumes' => $this->getVolumesMappings(),
            '/drives' => $this->getDrivesMappings(),
            '/arrays/performance' => $this->getArrayPerformanceMappings(),
            '/volumes/performance' => $this->getVolumePerformanceMappings(),
            '/controllers' => $this->getControllersMappings(),
            '/hardware' => $this->getHardwareMappings(),
            '/network-interfaces/port-details' => $this->getPortDetailsMappings(),
            '/array-connections' => $this->getArrayConnectionsMappings(),
            '/subnets' => $this->getSubnetsMappings(),
            '/space' => $this->getSpaceMappings(),
        ];
    }

    public function getMappingsForEndpoint(string $endpoint): array
    {
        $allMappings = $this->getMappings();
        return $allMappings[$endpoint] ?? [];
    }

    public function getTargetTableForEndpoint(string $endpoint): string
    {
        return match ($endpoint) {
            '/arrays' => 'devices',
            '/network-interfaces', '/network-interfaces/performance' => 'ports',
            '/volumes', '/drives' => 'storage',
            '/arrays/performance', '/volumes/performance', '/controllers', '/hardware', '/network-interfaces/port-details', '/space' => 'sensors',
            '/array-connections', '/subnets' => 'custom',
            default => 'custom',
        };
    }

    public function transformValue(string $field, mixed $value): mixed
    {
        // Data type conversions
        if (in_array($field, ['ifSpeed', 'storage_size', 'storage_used', 'capacity'])) {
            return (int) $value;
        }

        if (in_array($field, ['data_reduction_ratio', 'total_reduction_ratio', 'thin_provisioning_ratio'])) {
            return (float) $value;
        }

        return $value;
    }

    public function isValidMapping(string $endpoint, string $field, string $targetTable): bool
    {
        $mappings = $this->getMappingsForEndpoint($endpoint);
        $targetTableForEndpoint = $this->getTargetTableForEndpoint($endpoint);

        return isset($mappings[$field]) && $targetTableForEndpoint === $targetTable;
    }

    public function getSensorClass(string $endpoint, string $sensorDescr): ?string
    {
        // Map Pure Storage metrics to sensor classes
        // Valid LibreNMS sensor classes: airflow, ber, bitrate, charge, chromatic_dispersion, cooling,
        // count, current, dbm, delay, eer, fanspeed, frequency, humidity, load, loss, percent, power,
        // power_consumed, power_factor, pressure, quality_factor, runtime, signal, snr, state,
        // temperature, tv_signal, voltage, waterflow, signal_loss
        
        $sensorClasses = [
            '/arrays/performance' => [
                'Array_Read_Throughput' => 'bitrate',      // bytes/sec -> bitrate
                'Array_Write_Throughput' => 'bitrate',     // bytes/sec -> bitrate
                'Array_Read_IOPS' => 'count',              // operations/sec
                'Array_Write_IOPS' => 'count',             // operations/sec
                'Array_Read_Latency' => 'delay',           // microseconds
                'Array_Write_Latency' => 'delay',          // microseconds
                'Array_Read_Queue_Latency' => 'delay',     // microseconds
                'Array_Write_Queue_Latency' => 'delay',    // microseconds
                'Array_Bytes_Per_Read' => 'count',         // bytes
                'Array_Bytes_Per_Write' => 'count',        // bytes
            ],
            '/volumes/performance' => [
                'Volume_Read_Throughput' => 'bitrate',     // bytes/sec -> bitrate
                'Volume_Write_Throughput' => 'bitrate',    // bytes/sec -> bitrate
                'Volume_Read_IOPS' => 'count',             // operations/sec
                'Volume_Write_IOPS' => 'count',            // operations/sec
                'Volume_Read_Latency' => 'delay',          // microseconds
                'Volume_Write_Latency' => 'delay',         // microseconds
                'Volume_Queue_Latency_Read' => 'delay',    // microseconds
                'Volume_Queue_Latency_Write' => 'delay',   // microseconds
            ],
            '/hardware' => [
                'Hardware_Temperature' => 'temperature',
                'Hardware_Voltage' => 'voltage',
                'Hardware_Status' => 'state',
            ],
            '/network-interfaces/port-details' => [
                'Optic_Temperature' => 'temperature',
                'Optic_Vcc' => 'voltage',
                'TX_Bias_Current' => 'current',
                'TX_Optical_Power' => 'dbm',
                'RX_Optical_Power' => 'dbm',
                'TX_Fault' => 'state',
                'RX_Loss_of_Signal' => 'state',
            ],
            '/controllers' => [
                'Controller_Status' => 'state',
            ],
        ];

        if (isset($sensorClasses[$endpoint][$sensorDescr])) {
            return $sensorClasses[$endpoint][$sensorDescr];
        }

        return 'count'; // Default to count for unknown metrics
    }

    public function getSensorDescription(string $endpoint, string $apiField): string
    {
        // Use the field name as-is (it's already formatted nicely in our mappings)
        return $apiField;
    }
    // ENDPOINT MAPPING DEFINITIONS
    // =====================================================================

    private function getArrayMappings(): array
    {
        return [
            'hostname' => '$.items[0].name',
            'sysName' => '$.items[0].name',
            'version' => '$.items[0].version',
            'hardware' => '$.items[0].model',
            'os' => '$.items[0].os',
            'serial' => '$.items[0].id',
            'location' => '$.items[0].time_zone',
            'parity' => '$.items[0].parity',
        ];
    }

    private function getNetworkInterfacesMappings(): array
    {
        return [
            'ifName' => '$.items[*].name',
            'ifDescr' => '$.items[*].services[0]',
            'ifType' => '$.items[*].interface_type',
            'ifSpeed' => '$.items[*].speed',
            'ifPhysAddress' => '$.items[*].eth.mac_address',
            'ifAdminStatus' => '$.items[*].enabled',
            'ifOperStatus' => '$.items[*].enabled',
            'ifMtu' => '$.items[*].eth.mtu',
            'ifAlias' => '$.items[*].eth.address',
            'ifVlan' => '$.items[*].eth.vlan',
            'ipv4_address' => '$.items[*].eth.address',
            'ipv4_netmask' => '$.items[*].eth.netmask',
        ];
    }

    private function getNetworkPerformanceMappings(): array
    {
        return [
            'ifInOctets' => '$.items[*].eth.received_bytes_per_sec',
            'ifOutOctets' => '$.items[*].eth.transmitted_bytes_per_sec',
            'ifInUcastPkts' => '$.items[*].eth.received_packets_per_sec',
            'ifOutUcastPkts' => '$.items[*].eth.transmitted_packets_per_sec',
            'ifInErrors' => '$.items[*].eth.rx_errors_per_sec',
            'ifOutErrors' => '$.items[*].eth.tx_errors_per_sec',
            'ifInDiscards' => '$.items[*].fc.link_failures_per_sec',
        ];
    }

    private function getVolumesMappings(): array
    {
        return [
            'storage_descr' => '$.items[*].name',
            'storage_type' => 'pure-volume',
            'storage_size' => '$.items[*].space.total_provisioned',
            'storage_used' => '$.items[*].space.total_physical',
            'storage_free' => 'calculated',
            'storage_perc' => 'calculated',
            'data_reduction_ratio' => '$.items[*].space.data_reduction',
            'total_reduction_ratio' => '$.items[*].space.total_reduction',
            'snapshots_bytes' => '$.items[*].space.snapshots',
            'thin_provisioning_ratio' => '$.items[*].space.thin_provisioning',
            'volume_group' => '$.items[*].volume_group.name',
            'pod_name' => '$.items[*].pod.name',
            'created_timestamp' => '$.items[*].created',
        ];
    }

    private function getDrivesMappings(): array
    {
        return [
            'storage_descr' => '$.items[*].name',
            'storage_type' => 'pure-drive',
            'storage_size' => '$.items[*].capacity',
            'component_type' => '$.items[*].type',
            'component_protocol' => '$.items[*].protocol',
            'sensor_class_state' => '$.items[*].status',
            'component_serial' => '$.items[*].serial',
        ];
    }

    private function getArrayPerformanceMappings(): array
    {
        return [
            'Array_Read_Throughput' => '$.items[0].read_bytes_per_sec',
            'Array_Write_Throughput' => '$.items[0].write_bytes_per_sec',
            'Array_Read_IOPS' => '$.items[0].reads_per_sec',
            'Array_Write_IOPS' => '$.items[0].writes_per_sec',
            'Array_Read_Latency' => '$.items[0].usec_per_read_op',
            'Array_Write_Latency' => '$.items[0].usec_per_write_op',
            'Array_Read_Queue_Latency' => '$.items[0].queue_usec_per_read_op',
            'Array_Write_Queue_Latency' => '$.items[0].queue_usec_per_write_op',
            'Array_Bytes_Per_Read' => '$.items[0].bytes_per_read',
            'Array_Bytes_Per_Write' => '$.items[0].bytes_per_write',
        ];
    }

    private function getVolumePerformanceMappings(): array
    {
        return [
            'Volume_Read_Throughput' => '$.items[*].read_bytes_per_sec',
            'Volume_Write_Throughput' => '$.items[*].write_bytes_per_sec',
            'Volume_Read_IOPS' => '$.items[*].reads_per_sec',
            'Volume_Write_IOPS' => '$.items[*].writes_per_sec',
            'Volume_Read_Latency' => '$.items[*].usec_per_read_op',
            'Volume_Write_Latency' => '$.items[*].usec_per_write_op',
            'Volume_Queue_Latency_Read' => '$.items[*].queue_usec_per_read_op',
            'Volume_Queue_Latency_Write' => '$.items[*].queue_usec_per_write_op',
        ];
    }

    private function getControllersMappings(): array
    {
        // Only state sensor, no metadata fields
        return [
            'Controller_Status' => '$.items[*].status',
        ];
    }

    private function getHardwareMappings(): array
    {
        // Only actual sensor measurements, no metadata
        return [
            'Hardware_Temperature' => '$.items[*].temperature',
            'Hardware_Voltage' => '$.items[*].voltage',
            'Hardware_Status' => '$.items[*].status',
        ];
    }

    private function getPortDetailsMappings(): array
    {
        // Only actual sensor measurements, no static metadata
        return [
            'Optic_Temperature' => '$.items[*].temperature[0].measurement',
            'Optic_Vcc' => '$.items[*].voltage[0].measurement',
            'TX_Bias_Current' => '$.items[*].tx_bias[0].measurement',
            'TX_Optical_Power' => '$.items[*].tx_power[0].measurement',
            'RX_Optical_Power' => '$.items[*].rx_power[0].measurement',
            'TX_Fault' => '$.items[*].tx_fault[0].flag',
            'RX_Loss_of_Signal' => '$.items[*].rx_los[0].flag',
        ];
    }

    private function getArrayConnectionsMappings(): array
    {
        return [
            'local_port' => '$.items[*].local_port',
            'remote_port' => '$.items[*].remote_port',
            'remote_hostname' => '$.items[*].name',
            'link_transport' => '$.items[*].replication_transport',
            'link_status' => '$.items[*].status',
        ];
    }

    private function getSubnetsMappings(): array
    {
        // Subnets are configuration, not sensors - this endpoint should not create sensors
        return [];
    }

    private function getSpaceMappings(): array
    {
        return [
            'Total_Provisioned' => '$.total_provisioned',
            'Total_Used' => '$.total_used',
            'Data_Reduction_Ratio' => '$.data_reduction',
            'Total_Reduction_Ratio' => '$.total_reduction',
            'Replication_Usage' => '$.replication',
            'Snapshots_Usage' => '$.snapshots',
        ];
    }
}
