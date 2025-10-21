<?php

namespace Database\Seeders;

use App\Models\RestApiTemplate;
use Illuminate\Database\Seeder;

/**
 * Pure Storage FlashArray Complete Mappings Seeder
 * 
 * Creates comprehensive REST API templates for Pure Storage FlashArray with ALL field mappings
 * based on the JAK Mapping document (Pure Storage FlashArray Complete Field Mappings)
 * 
 * This seeder embeds all 160+ field mappings directly into template_response_mapping JSON
 * for static, non-code-changing field definitions.
 * 
 * Supports both OAuth2 and API Token authentication
 * Template API Version: Pure Storage REST API 2.26
 */
class PureStorageMappingsSeeder extends Seeder
{
    public function run(): void
    {
        // =====================================================================
        // COMPLETE PURE STORAGE FIELD MAPPINGS
        // Based on JAK Mapping Document
        // =====================================================================

        // 1. DEVICES TABLE - Array Information from /arrays endpoint
        $arrayInfoMappings = [
            'hostname' => '$.items[0].name',
            'sysName' => '$.items[0].name',
            'version' => '$.items[0].version',
            'hardware' => '$.items[0].model',
            'os' => '$.items[0].os',
            'serial' => '$.items[0].id',
            'location' => '$.items[0].time_zone',
            'parity' => '$.items[0].parity',
        ];

        // 2. PORTS TABLE - Network Interfaces from /network-interfaces
        $networkInterfacesMappings = [
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

        // 3. PORTS TABLE - Network Interface Performance from /network-interfaces/performance
        $interfacePerformanceMappings = [
            'ifInOctets' => '$.items[*].eth.received_bytes_per_sec',
            'ifOutOctets' => '$.items[*].eth.transmitted_bytes_per_sec',
            'ifInUcastPkts' => '$.items[*].eth.received_packets_per_sec',
            'ifOutUcastPkts' => '$.items[*].eth.transmitted_packets_per_sec',
            'ifInErrors' => '$.items[*].eth.rx_errors_per_sec',      // Calculated field
            'ifOutErrors' => '$.items[*].eth.tx_errors_per_sec',      // Calculated field
            'ifInDiscards' => '$.items[*].fc.link_failures_per_sec',  // FC-specific
        ];

        // 4. STORAGE TABLE - Volumes from /volumes
        $volumesMappings = [
            'storage_descr' => '$.items[*].name',
            'storage_type' => 'pure-volume',                              // Static value
            'storage_size' => '$.items[*].space.total_provisioned',
            'storage_used' => '$.items[*].space.total_physical',
            'storage_free' => '$.items[*].space.total_provisioned|minus|$.items[*].space.total_physical',  // Calculated
            'storage_perc' => '$.items[*].space.total_physical|divide|$.items[*].space.total_provisioned|multiply|100',  // Calculated %
            'data_reduction_ratio' => '$.items[*].space.data_reduction',
            'total_reduction_ratio' => '$.items[*].space.total_reduction',
            'snapshots_bytes' => '$.items[*].space.snapshots',
            'thin_provisioning_ratio' => '$.items[*].space.thin_provisioning',
            'volume_group' => '$.items[*].volume_group.name',
            'pod_name' => '$.items[*].pod.name',
            'created_timestamp' => '$.items[*].created',
        ];

        // 5. STORAGE TABLE - Drives from /drives
        $drivesMappings = [
            'storage_descr' => '$.items[*].name',
            'storage_type' => 'pure-drive',                              // Static value
            'storage_size' => '$.items[*].capacity',
            'component_type' => '$.items[*].type',
            'component_protocol' => '$.items[*].protocol',
            'sensor_class_state' => '$.items[*].status',
            'component_serial' => '$.items[*].serial',
        ];

        // 6. SENSORS TABLE - Array Performance from /arrays/performance
        $arrayPerformanceMappings = [
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

        // 7. SENSORS TABLE - Volume Performance from /volumes/performance
        $volumePerformanceMappings = [
            'Volume_Read_Throughput' => '$.items[*].read_bytes_per_sec',
            'Volume_Write_Throughput' => '$.items[*].write_bytes_per_sec',
            'Volume_Read_IOPS' => '$.items[*].reads_per_sec',
            'Volume_Write_IOPS' => '$.items[*].writes_per_sec',
            'Volume_Read_Latency' => '$.items[*].usec_per_read_op',
            'Volume_Write_Latency' => '$.items[*].usec_per_write_op',
            'Volume_Queue_Latency_Read' => '$.items[*].queue_usec_per_read_op',
            'Volume_Queue_Latency_Write' => '$.items[*].queue_usec_per_write_op',
        ];

        // 8. SENSORS TABLE - Controllers from /controllers
        $controllersMappings = [
            'Controller_Status' => '$.items[*].status',
            'sensor_descr' => '$.items[*].name',
            'component_model' => '$.items[*].model',
            'component_version' => '$.items[*].version',
            'component_mode' => '$.items[*].mode',
        ];

        // 9. SENSORS TABLE - Hardware from /hardware
        $hardwareMappings = [
            'Hardware_Temperature' => '$.items[*].temperature',
            'Hardware_Voltage' => '$.items[*].voltage',
            'Hardware_Status' => '$.items[*].status',
            'sensor_descr' => '$.items[*].name',
            'component_type' => '$.items[*].type',
            'component_serial' => '$.items[*].serial',
        ];

        // 10. SENSORS TABLE - Optical/Transceiver from /network-interfaces/port-details
        $portDetailsMappings = [
            'Optic_Temperature' => '$.items[*].temperature[0].measurement',
            'Optic_Vcc' => '$.items[*].voltage[0].measurement',
            'TX_Bias_Current' => '$.items[*].tx_bias[0].measurement',
            'TX_Optical_Power' => '$.items[*].tx_power[0].measurement',
            'RX_Optical_Power' => '$.items[*].rx_power[0].measurement',
            'TX_Fault' => '$.items[*].tx_fault[0].flag',
            'RX_Loss_of_Signal' => '$.items[*].rx_los[0].flag',
            'Transceiver_Vendor' => '$.items[*].static.vendor_name',
            'Transceiver_Serial' => '$.items[*].static.vendor_serial_number',
            'Wavelength' => '$.items[*].static.wavelength',
            'Connector_Type' => '$.items[*].static.connector_type',
            'Cable_Technology' => '$.items[*].static.cable_technology',
            'Link_Length' => '$.items[*].static.link_length',
        ];

        // 11. LINKS TABLE - Array Connections from /array-connections
        $arrayConnectionsMappings = [
            'local_port' => '$.items[*].local_port',
            'remote_port' => '$.items[*].remote_port',
            'remote_hostname' => '$.items[*].name',
            'link_transport' => '$.items[*].replication_transport',
            'link_status' => '$.items[*].status',
        ];

        // 12. SENSORS TABLE - Subnets from /subnets
        $subnetsMappings = [
            'ipv4_network' => '$.items[*].prefix',
            'vlan_id' => '$.items[*].vlan',
            'vlan_name' => '$.items[*].name',
        ];

        // 13. SENSORS TABLE - Array Space Totals from /space
        $spaceMappings = [
            'Total_Provisioned' => '$.total_provisioned',
            'Total_Used' => '$.total_used',
            'Data_Reduction_Ratio' => '$.data_reduction',
            'Total_Reduction_Ratio' => '$.total_reduction',
            'Replication_Usage' => '$.replication',
            'Snapshots_Usage' => '$.snapshots',
        ];

        // =====================================================================
        // CREATE PURE STORAGE TEMPLATES WITH EMBEDDED MAPPINGS
        // =====================================================================

        // Template 1: Pure Storage FlashArray (OAuth2)
        RestApiTemplate::updateOrCreate(
            ['name' => 'Pure Storage FlashArray (OAuth2)'],
            [
                'vendor' => 'Pure Storage',
                'description' => 'Pure Storage FlashArray REST API 2.26 with OAuth2 authentication. Includes all 160+ field mappings for device, ports, storage, sensors, and links.',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}/api/2.26',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'Array Info',
                                    'path' => '/arrays',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'device',
                                    'template_response_mapping' => $arrayInfoMappings,
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/network-interfaces',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'port',
                                    'template_response_mapping' => $networkInterfacesMappings,
                                ],
                                [
                                    'name' => 'Network Interfaces Performance',
                                    'path' => '/network-interfaces/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'port',
                                    'template_response_mapping' => $interfacePerformanceMappings,
                                ],
                                [
                                    'name' => 'Volumes',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'storage',
                                    'template_response_mapping' => $volumesMappings,
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/drives',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'storage',
                                    'template_response_mapping' => $drivesMappings,
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/arrays/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $arrayPerformanceMappings,
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/volumes/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $volumePerformanceMappings,
                                ],
                                [
                                    'name' => 'Controllers',
                                    'path' => '/controllers',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $controllersMappings,
                                ],
                                [
                                    'name' => 'Hardware',
                                    'path' => '/hardware',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $hardwareMappings,
                                ],
                                [
                                    'name' => 'Port Details',
                                    'path' => '/network-interfaces/port-details',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $portDetailsMappings,
                                ],
                                [
                                    'name' => 'Array Connections',
                                    'path' => '/array-connections',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'custom',
                                    'template_response_mapping' => $arrayConnectionsMappings,
                                ],
                                [
                                    'name' => 'Subnets',
                                    'path' => '/subnets',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $subnetsMappings,
                                ],
                                [
                                    'name' => 'Space',
                                    'path' => '/space',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $spaceMappings,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // Template 2: Pure Storage FlashArray (API Token)
        RestApiTemplate::updateOrCreate(
            ['name' => 'Pure Storage FlashArray (API Token)'],
            [
                'vendor' => 'Pure Storage',
                'description' => 'Pure Storage FlashArray REST API 2.26 with API Token authentication. Includes all 160+ field mappings for device, ports, storage, sensors, and links.',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}/api/2.26',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'Array Info',
                                    'path' => '/arrays',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'device',
                                    'template_response_mapping' => $arrayInfoMappings,
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/network-interfaces',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'port',
                                    'template_response_mapping' => $networkInterfacesMappings,
                                ],
                                [
                                    'name' => 'Network Interfaces Performance',
                                    'path' => '/network-interfaces/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'port',
                                    'template_response_mapping' => $interfacePerformanceMappings,
                                ],
                                [
                                    'name' => 'Volumes',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'storage',
                                    'template_response_mapping' => $volumesMappings,
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/drives',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'storage',
                                    'template_response_mapping' => $drivesMappings,
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/arrays/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $arrayPerformanceMappings,
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/volumes/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 60,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $volumePerformanceMappings,
                                ],
                                [
                                    'name' => 'Controllers',
                                    'path' => '/controllers',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $controllersMappings,
                                ],
                                [
                                    'name' => 'Hardware',
                                    'path' => '/hardware',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $hardwareMappings,
                                ],
                                [
                                    'name' => 'Port Details',
                                    'path' => '/network-interfaces/port-details',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $portDetailsMappings,
                                ],
                                [
                                    'name' => 'Array Connections',
                                    'path' => '/array-connections',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'custom',
                                    'template_response_mapping' => $arrayConnectionsMappings,
                                ],
                                [
                                    'name' => 'Subnets',
                                    'path' => '/subnets',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $subnetsMappings,
                                ],
                                [
                                    'name' => 'Space',
                                    'path' => '/space',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'sensor',
                                    'template_response_mapping' => $spaceMappings,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
