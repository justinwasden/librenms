<?php

namespace Database\Seeders;

use App\Models\RestApiTemplate;
use Illuminate\Database\Seeder;

/**
 * Pure Storage FlashArray Template Seeder
 * 
 * This seeder creates comprehensive REST API templates for Pure Storage FlashArray
 * with static mappings to native LibreNMS tables (devices, ports, storage, sensors).
 * 
 * Based on JAK Mapping document for Pure Storage FlashArray API 2.26
 * Supports both OAuth2 and API Token authentication methods
 */
class PureStorageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // =====================================================================
        // TEMPLATE RESPONSE MAPPINGS - All fields map to native LibreNMS tables
        // =====================================================================

        // DEVICES TABLE - Array-level information from /arrays endpoint
        $arrayInfoMapping = [
            "storage_type" => "pure-flasharray",
            "hostname" => "$.items[*].name",
            "sysName" => "$.items[*].name",
            "version" => "$.items[*].version",
            "hardware" => "$.items[*].model",
            "os" => "$.items[*].os",
            "serial" => "$.items[*].id",
            "location" => "$.items[*].time_zone",
            // Custom device attributes
            "parity" => "$.items[*].parity",
            "eradication_locked" => "$.items[*].eradication_locked",
        ];

        // PORTS TABLE - Network Interface information from /network-interfaces
        $networkInterfacesMapping = [
            "ifName" => "$.items[*].name",
            "ifDescr" => "$.items[*].services[0]",
            "ifType" => "$.items[*].interface_type",
            "ifSpeed" => "$.items[*].speed",
            "ifPhysAddress" => "$.items[*].eth.mac_address",
            "ifAdminStatus" => "$.items[*].enabled",
            "ifOperStatus" => "$.items[*].enabled",
            "ifMtu" => "$.items[*].eth.mtu",
            "ifAlias" => "$.items[*].eth.address",
            "ifVlan" => "$.items[*].eth.vlan",
            // Additional IPv4 info for ipv4_addresses table
            "ipv4_address" => "$.items[*].eth.address",
            "ipv4_netmask" => "$.items[*].eth.netmask",
        ];

        // STORAGE TABLE - Volumes from /volumes endpoint
        $volumesInfoMapping = [
            "storage_descr" => "$.items[*].name",
            "storage_type" => "pure-volume",
            "storage_size" => "$.items[*].space.total_provisioned",
            "storage_used" => "$.items[*].space.total_physical",
            "storage_free" => "calculated",
            "storage_perc" => "calculated",
            // Additional storage attributes
            "data_reduction_ratio" => "$.items[*].space.data_reduction",
            "total_reduction_ratio" => "$.items[*].space.total_reduction",
            "snapshots_bytes" => "$.items[*].space.snapshots",
            "thin_provisioning_ratio" => "$.items[*].space.thin_provisioning",
            "volume_group" => "$.items[*].volume_group.name",
            "pod_name" => "$.items[*].pod.name",
            "created_timestamp" => "$.items[*].created",
        ];

        // PORTS TABLE - Network Interface Performance from /network-interfaces/performance
        $interfacePerformanceMapping = [
            "ifInOctets" => "$.items[*].eth.received_bytes_per_sec",
            "ifOutOctets" => "$.items[*].eth.transmitted_bytes_per_sec",
            "ifInUcastPkts" => "$.items[*].eth.received_packets_per_sec",
            "ifOutUcastPkts" => "$.items[*].eth.transmitted_packets_per_sec",
            "ifInErrors" => "calculated_eth_errors",
            "ifOutErrors" => "calculated_eth_tx_errors",
            // FC performance if available
            "ifInDiscards" => "$.items[*].fc.link_failures_per_sec",
        ];

        // SENSORS TABLE - Array Performance from /arrays/performance
        $arrayPerformanceMapping = [
            "sensor_class_bandwidth_read" => "read_bytes_per_sec",
            "sensor_class_bandwidth_write" => "write_bytes_per_sec",
            "sensor_class_iops_read" => "reads_per_sec",
            "sensor_class_iops_write" => "writes_per_sec",
            "sensor_class_latency_read" => "usec_per_read_op",
            "sensor_class_latency_write" => "usec_per_write_op",
            "sensor_class_queue_latency_read" => "queue_usec_per_read_op",
            "sensor_class_queue_latency_write" => "queue_usec_per_write_op",
            "sensor_class_bytes_per_read" => "bytes_per_read",
            "sensor_class_bytes_per_write" => "bytes_per_write",
        ];

        // SENSORS TABLE - Volume Performance from /volumes/performance
        $volumePerformanceMapping = [
            "sensor_class_vol_bandwidth_read" => "read_bytes_per_sec",
            "sensor_class_vol_bandwidth_write" => "write_bytes_per_sec",
            "sensor_class_vol_iops_read" => "reads_per_sec",
            "sensor_class_vol_iops_write" => "writes_per_sec",
            "sensor_class_vol_latency_read" => "usec_per_read_op",
            "sensor_class_vol_latency_write" => "usec_per_write_op",
            "sensor_class_vol_queue_read" => "queue_usec_per_read_op",
            "sensor_class_vol_queue_write" => "queue_usec_per_write_op",
        ];

        // SENSORS TABLE - Hardware/Controllers status from /controllers
        $controllersMapping = [
            "sensor_class_state" => "$.items[*].status",
            "sensor_descr" => "$.items[*].name",
            "component_type" => "array_controller",
            "component_model" => "$.items[*].model",
            "component_version" => "$.items[*].version",
            "component_mode" => "$.items[*].mode",
        ];

        // SENSORS TABLE - Hardware components from /hardware
        $hardwareComponentsMapping = [
            "sensor_class_temperature" => "$.items[*].temperature",
            "sensor_class_voltage" => "$.items[*].voltage",
            "sensor_class_state" => "$.items[*].status",
            "sensor_descr" => "$.items[*].name",
            "component_type" => "$.items[*].type",
            "component_serial" => "$.items[*].serial",
        ];

        // STORAGE TABLE - Drives from /drives
        $drivesMapping = [
            "storage_descr" => "$.items[*].name",
            "storage_type" => "pure-drive",
            "storage_size" => "$.items[*].capacity",
            "component_type" => "$.items[*].type",
            "component_protocol" => "$.items[*].protocol",
            "sensor_class_state" => "$.items[*].status",
            "component_serial" => "$.items[*].serial",
        ];

        // SENSORS TABLE - Optical/Transceiver from /network-interfaces/port-details
        $portDetailsMapping = [
            "sensor_class_temp" => "$.items[*].temperature[0].measurement",
            "sensor_class_voltage" => "$.items[*].voltage[0].measurement",
            "sensor_class_tx_bias" => "$.items[*].tx_bias[0].measurement",
            "sensor_class_tx_power" => "$.items[*].tx_power[0].measurement",
            "sensor_class_rx_power" => "$.items[*].rx_power[0].measurement",
            "sensor_class_tx_fault" => "$.items[*].tx_fault[0].flag",
            "sensor_class_rx_los" => "$.items[*].rx_los[0].flag",
            // Static metadata
            "transceiver_vendor" => "$.items[*].static.vendor_name",
            "transceiver_serial" => "$.items[*].static.vendor_serial_number",
            "transceiver_wavelength" => "$.items[*].static.wavelength",
            "transceiver_connector" => "$.items[*].static.connector_type",
            "transceiver_cable_tech" => "$.items[*].static.cable_technology",
            "transceiver_link_length" => "$.items[*].static.link_length",
        ];

        // LINKS TABLE - Replication links from /array-connections
        $arrayConnectionsMapping = [
            "local_port" => "$.items[*].local_port",
            "remote_port" => "$.items[*].remote_port",
            "remote_hostname" => "$.items[*].name",
            "link_transport" => "$.items[*].replication_transport",
            "link_status" => "$.items[*].status",
        ];

        // SENSORS TABLE - Subnets/Network configuration from /subnets
        $subnetsMapping = [
            "ipv4_network" => "$.items[*].prefix",
            "vlan_id" => "$.items[*].vlan",
            "vlan_name" => "$.items[*].name",
        ];

        // SENSORS TABLE - Array space totals from /space
        $spaceMapping = [
            "sensor_total_provisioned" => "$.total_provisioned",
            "sensor_total_used" => "$.total_used",
            "sensor_data_reduction" => "$.data_reduction",
            "sensor_total_reduction" => "$.total_reduction",
            "sensor_replication_bytes" => "$.replication",
            "sensor_snapshots_bytes" => "$.snapshots",
        ];

        // =====================================================================
        // CREATE PURE STORAGE OAUTH2 TEMPLATE
        // =====================================================================
        RestApiTemplate::updateOrCreate(
            ['name' => 'Pure Storage FlashArray (OAuth2)'],
            [
                'vendor' => 'Pure Storage',
                'description' => 'Pure Storage FlashArray REST API 2.26 with OAuth2 authentication. Maps all endpoints to native LibreNMS tables.',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}/api/2.26',
                            'rate_limit' => 60,
                            'poll_interval' => 300,
                            'endpoints' => [
                                [
                                    'name' => 'Array Info',
                                    'path' => '/arrays',
                                    'http_method' => 'GET',
                                    'resource_type' => 'device',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'table' => 'devices',
                                    'template_response_mapping' => $arrayInfoMapping,
                                ],
                                [
                                    'name' => 'Controllers',
                                    'path' => '/controllers',
                                    'http_method' => 'GET',
                                    'resource_type' => 'component',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $controllersMapping,
                                ],
                                [
                                    'name' => 'Volumes',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'resource_type' => 'volume',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'storage',
                                    'template_response_mapping' => $volumesInfoMapping,
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/network-interfaces',
                                    'http_method' => 'GET',
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'ports',
                                    'template_response_mapping' => $networkInterfacesMapping,
                                ],
                                [
                                    'name' => 'Network Interface Performance',
                                    'path' => '/network-interfaces/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'ports',
                                    'template_response_mapping' => $interfacePerformanceMapping,
                                ],
                                [
                                    'name' => 'Port Details',
                                    'path' => '/network-interfaces/port-details',
                                    'http_method' => 'GET',
                                    'resource_type' => 'optic',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $portDetailsMapping,
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/arrays/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'array_perf',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $arrayPerformanceMapping,
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/volumes/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'volume_perf',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $volumePerformanceMapping,
                                ],
                                [
                                    'name' => 'Hardware Components',
                                    'path' => '/hardware',
                                    'http_method' => 'GET',
                                    'resource_type' => 'hardware',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $hardwareComponentsMapping,
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/drives',
                                    'http_method' => 'GET',
                                    'resource_type' => 'drive',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'storage',
                                    'template_response_mapping' => $drivesMapping,
                                ],
                                [
                                    'name' => 'Array Connections',
                                    'path' => '/array-connections',
                                    'http_method' => 'GET',
                                    'resource_type' => 'link',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'links',
                                    'template_response_mapping' => $arrayConnectionsMapping,
                                ],
                                [
                                    'name' => 'Subnets',
                                    'path' => '/subnets',
                                    'http_method' => 'GET',
                                    'resource_type' => 'subnet',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $subnetsMapping,
                                ],
                                [
                                    'name' => 'Array Space',
                                    'path' => '/space',
                                    'http_method' => 'GET',
                                    'resource_type' => 'array_space',
                                    'resource_id_field' => 'array',
                                    'resource_name_field' => 'array',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $spaceMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // =====================================================================
        // CREATE PURE STORAGE API TOKEN TEMPLATE
        // =====================================================================
        RestApiTemplate::updateOrCreate(
            ['name' => 'Pure Storage FlashArray (API Token)'],
            [
                'vendor' => 'Pure Storage',
                'description' => 'Pure Storage FlashArray REST API 2.26 with API Token authentication. Maps all endpoints to native LibreNMS tables.',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}/api/2.26',
                            'rate_limit' => 60,
                            'poll_interval' => 300,
                            'endpoints' => [
                                [
                                    'name' => 'Array Info',
                                    'path' => '/arrays',
                                    'http_method' => 'GET',
                                    'resource_type' => 'device',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'table' => 'devices',
                                    'template_response_mapping' => $arrayInfoMapping,
                                ],
                                [
                                    'name' => 'Controllers',
                                    'path' => '/controllers',
                                    'http_method' => 'GET',
                                    'resource_type' => 'component',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $controllersMapping,
                                ],
                                [
                                    'name' => 'Volumes',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'resource_type' => 'volume',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'storage',
                                    'template_response_mapping' => $volumesInfoMapping,
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/network-interfaces',
                                    'http_method' => 'GET',
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'ports',
                                    'template_response_mapping' => $networkInterfacesMapping,
                                ],
                                [
                                    'name' => 'Network Interface Performance',
                                    'path' => '/network-interfaces/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'ports',
                                    'template_response_mapping' => $interfacePerformanceMapping,
                                ],
                                [
                                    'name' => 'Port Details',
                                    'path' => '/network-interfaces/port-details',
                                    'http_method' => 'GET',
                                    'resource_type' => 'optic',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $portDetailsMapping,
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/arrays/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'array_perf',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $arrayPerformanceMapping,
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/volumes/performance',
                                    'http_method' => 'GET',
                                    'resource_type' => 'volume_perf',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $volumePerformanceMapping,
                                ],
                                [
                                    'name' => 'Hardware Components',
                                    'path' => '/hardware',
                                    'http_method' => 'GET',
                                    'resource_type' => 'hardware',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $hardwareComponentsMapping,
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/drives',
                                    'http_method' => 'GET',
                                    'resource_type' => 'drive',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'storage',
                                    'template_response_mapping' => $drivesMapping,
                                ],
                                [
                                    'name' => 'Array Connections',
                                    'path' => '/array-connections',
                                    'http_method' => 'GET',
                                    'resource_type' => 'link',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'links',
                                    'template_response_mapping' => $arrayConnectionsMapping,
                                ],
                                [
                                    'name' => 'Subnets',
                                    'path' => '/subnets',
                                    'http_method' => 'GET',
                                    'resource_type' => 'subnet',
                                    'resource_id_field' => 'items.*.name',
                                    'resource_name_field' => 'items.*.name',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $subnetsMapping,
                                ],
                                [
                                    'name' => 'Array Space',
                                    'path' => '/space',
                                    'http_method' => 'GET',
                                    'resource_type' => 'array_space',
                                    'resource_id_field' => 'array',
                                    'resource_name_field' => 'array',
                                    'table' => 'sensors',
                                    'template_response_mapping' => $spaceMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->command->info('✓ Pure Storage FlashArray templates created with static mappings');
    }
}
