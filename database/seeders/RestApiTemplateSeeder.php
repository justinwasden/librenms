<?php

namespace Database\Seeders;

use App\Models\RestApiTemplate;
use Illuminate\Database\Seeder;

class RestApiTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define common metric mapping arrays.
        // NOTE: Metric names changed to match LibreNMS database fields (e.g., 'storage_size', 'ifDescr', 'state').

        $pureStorageArrayInfoMapping = [
            "storage_size" => "items.0.space.total_physical",
            "storage_used" => "items.0.space.total_used",
            "available_capacity" => "items.0.space.total_provisioned",
            "array_data_reduction" => "items.0.space.data_reduction",
            "array_total_reduction" => "items.0.space.total_reduction",
            "array_capacity" => "items.0.capacity",
            "storage_descr" => "items.0.name", // Mapped to storage description
            "array_id" => "items.0.id",
            "array_version" => "items.0.version",
        ];

        $pureStorageVolumesInfoMapping = [
            "storage_descr" => "name", // Mapped to storage description
            "storage_size" => "provisioned", // Mapped to storage size
            "total_physical" => "space.total_physical",
            "storage_used" => "space.total_used",
            "volume_snapshots" => "space.snapshots",
            "volume_data_reduction" => "space.data_reduction",
            "volume_total_reduction" => "space.total_reduction",
            "volume_connections" => "connection_count",
            "volume_group" => "volume_group.name",
            "volume_pod" => "pod.name",
            "volume_created" => "created",
            "volume_serial" => "serial",
        ];

        $pureStorageControllersStatusMapping = [
            // Controllers are components; status maps better to sensor field "state" or "status"
            "device_hardware" => "items.0.name", // Stored as component name/label
            "hardware" => "items.0.model",
            "state" => "items.0.status", // Using generic state field for status indicator
            "controller_mode" => "items.0.mode",
            "version" => "items.0.version",
        ];

        $pureStorageArrayPerformanceMapping = [
            "bw_read" => "items.0.read_bytes_per_sec",
            "bw_write" => "items.0.write_bytes_per_sec",
            "latency_read" => "items.0.usec_per_read_op",
            "latency_write" => "items.0.usec_per_write_op",
            "array_reads_per_sec" => "items.0.reads_per_sec",
            "array_writes_per_sec" => "items.0.writes_per_sec",
            "array_queue_usec_per_read_op" => "items.0.queue_usec_per_read_op",
            "array_queue_usec_per_write_op" => "items.0.queue_usec_per_write_op",
            "array_bytes_per_read" => "items.0.bytes_per_read",
            "array_bytes_per_write" => "items.0.bytes_per_write",
        ];

        $pureStorageVolumePerformanceMapping = [
            "iops_read" => "reads_per_sec",
            "iops_write" => "writes_per_sec",
            "latency_read" => "usec_per_read_op",
            "latency_write" => "usec_per_write_op",
            "bw_read" => "read_bytes_per_sec",
            "bw_write" => "write_bytes_per_sec",
            "volume_queue_usec_per_read_op" => "queue_usec_per_read_op",
            "volume_queue_usec_per_write_op" => "queue_usec_per_write_op",
            "volume_bytes_per_read" => "bytes_per_read",
            "volume_bytes_per_write" => "bytes_per_write",
        ];

        $pureStorageAlertsMapping = [
            // Alerts typically map to the eventlog/alerts table structure, not component metrics
            "alert_id" => "id",
            "alert_state" => "state",
            "alert_code" => "code",
            "alert_severity" => "severity",
            "alert_created" => "created",
            "alert_updated" => "updated",
            "alert_issue" => "issue",
            "alert_knowledge_base_url" => "knowledge_base_url",
            "alert_summary" => "summary",
        ];

        $pureStorageHardwareComponentsMapping = [
            "hardware_name" => "name",
            "state" => "status", // Status mapped to generic "state" field
            "hardware_type" => "type",
            "hardware_serial" => "serial",
            "temperature" => "temperature", // Maps to standard sensor value
            "voltage" => "voltage", // Maps to standard sensor value
        ];

        $pureStorageDrivesMapping = [
            "storage_descr" => "name", // Drive name maps well to storage description
            "state" => "status", // Drive status mapped to sensor "state"
            "sensor_class" => "type", // Drive type mapped to sensor class
            "storage_size" => "capacity", // Drive capacity mapped to storage size
            "drive_protocol" => "protocol",
            "drive_details" => "details",
        ];

        $pureStorageNetworkInterfacesMapping = [
            "ifDescr" => "name", // Mapped to primary interface description
            "ifPhysAddress" => "eth.mac_address",
            "ifAdminStatus" => "enabled",
            "ifOperStatus" => "enabled",
            "ifSpeed" => "speed",
            "ifMtu" => "eth.mtu",
            "ifType" => "interface_type",
            "ipv4_address" => "eth.address",
            "ifVlan" => "eth.vlan",
        ];

        $pureStorageHostsMapping = [
            "host_name" => "name",
            "host_group" => "host_group.name",
            "host_connections" => "connection_count",
            "host_connection_status" => "port_connectivity.status",
            "host_connection_details" => "port_connectivity.details",
            "host_totalspace" => "space.total_physical",
            "host_provisioned_space" => "space.total_provisioned",
            "host_total_reduction" => "space.total_reduction",
        ];

        // Define mapping arrays for the networking templates (1-5)
        // to be encoded below.

        $arubaSystemInfoMapping = [
            "hostname" => "hostname",
            "uptime" => "boot_time",
            "version" => "software_version",
        ];

        $arubaInterfaceStatsMapping = [
            "rx_bytes" => "rx_bytes",
            "tx_bytes" => "tx_bytes",
            "rx_packets" => "rx_packets",
            "tx_packets" => "tx_packets",
        ];

        $ciscoCpuUtilizationMapping = [
            "five_sec_avg" => "cpu-utilization.five-seconds-average",
            "one_min_avg" => "cpu-utilization.one-minute-average",
            "five_min_avg" => "cpu-utilization.five-minutes-average",
        ];

        $ciscoMemoryStatsMapping = [
            "total_memory" => "memory-statistics.total-memory",
            "used_memory" => "memory-statistics.used-memory",
            "free_memory" => "memory-statistics.free-memory",
        ];

        $fortinetSystemStatusMapping = [
            "hostname" => "results.hostname",
            "firmware_version" => "results.version",
            "model_name" => "results.model_name",
            "serial_number" => "results.serial",
            "status" => "status",
        ];

        $fortinetResourceUsageMapping = [
            "cpu_current" => "results.cpu.0.current",
            "cpu_average_1min" => "results.cpu.0.historical.1-min.average",
            "mem_current" => "results.mem.0.current",
            "mem_average_1min" => "results.mem.0.historical.1-min.average",
            "disk_current" => "results.disk.0.current",
            "disk_average_1min" => "results.disk.0.historical.1-min.average",
        ];

        $fortinetSessionStatsMapping = [
            "session_current" => "session.0.current",
            "session_average_1min" => "session.0.historical.1-min.average",
        ];

        $fortinetVpnStatusMapping = [
            "vpn_users_active" => "results.0.users",
        ];

        $fortinetSecurityEventsMapping = [
            "log_disk_used_bytes" => "results.used_bytes",
            "log_disk_free_bytes" => "results.free_bytes",
            "log_disk_total_bytes" => "results.total_bytes",
        ];

        $juniperSystemUptimeMapping = [
            "uptime_seconds" => "system-uptime-information.up-time.seconds",
        ];

        $juniperInterfaceStatsMapping = [
            "ge-0/0/0_rx_bytes" => "interface-statistics.physical-interface.0.input-bytes",
            "ge-0/0/0_tx_bytes" => "interface-statistics.physical-interface.0.output-bytes",
        ];

        $paloAltoSystemInfoMapping = [
            "hostname" => "result.system.hostname",
            "uptime" => "result.system.uptime",
            "version" => "result.system.sw-version",
        ];

        $paloAltoInterfaceStatsMapping = [
            "ethernet1/1_rx_bytes" => "result.interface.ethernet1/1.stats.ibytes",
            "ethernet1/1_tx_bytes" => "result.interface.ethernet1/1.stats.obytes",
        ];

        $paloAltoTopAppsMapping = [
            "report_name" => "report.@attributes.reportname",
            "result_name" => "report.result.@attributes.name",
            "networking_category_name" => "report.result.entry.0.category-of-name",
            "networking_sessions" => "report.result.entry.0.nsess",
            "networking_bytes" => "report.result.entry.0.nbytes",
        ];


        // Define all templates
        $templates = [
            // ---------------------------------------------------------------------
            // 1. ARUBA CX
            // ---------------------------------------------------------------------
            [
                "name" => "Aruba CX",
                "vendor" => "Aruba",
                "description" => "Standard REST API endpoints for Aruba CX switches (JSON based). Requires Basic Auth (ID 2).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}/rest",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "System Info",
                                    "path" => "/v10.04/system",
                                    "method" => "GET",
                                    "resource_type" => "device",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $arubaSystemInfoMapping,
                                ],
                                [
                                    "name" => "Interface Statistics",
                                    "path" => "/v10.04/system/interfaces/*/statistics",
                                    "method" => "GET",
                                    "resource_type" => "port",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $arubaInterfaceStatsMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 2. CISCO IOS XE (RESTCONF)
            // ---------------------------------------------------------------------
            [
                "name" => "Cisco IOS XE (RESTCONF)",
                "vendor" => "Cisco",
                "description" => "Common RESTCONF API endpoints for Cisco IOS XE devices (JSON based). Requires Basic Auth (ID 2).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "CPU Utilization",
                                    "path" => "/restconf/data/Cisco-IOS-XE-process-cpu-oper:cpu-usage/cpu-utilization",
                                    "method" => "GET",
                                    "resource_type" => "processor",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $ciscoCpuUtilizationMapping,
                                ],
                                [
                                    "name" => "Memory Statistics",
                                    "path" => "/restconf/data/Cisco-IOS-XE-memory-oper:memory-statistics",
                                    "method" => "GET",
                                    "resource_type" => "mempool",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $ciscoMemoryStatsMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 3. FORTINET FORTIGATE
            // ---------------------------------------------------------------------
            [
                "name" => "Fortinet FortiGate",
                "vendor" => "Fortinet",
                "description" => "Common API endpoints for Fortinet FortiGate devices (JSON based). Requires Token/Bearer Auth (ID 3).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}",
                            "rate_limit" => 120,
                            "endpoints" => [
                                [
                                    "name" => "System Status",
                                    "path" => "/api/v2/monitor/system/status",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "device",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $fortinetSystemStatusMapping,
                                ],
                                [
                                    "name" => "CPU and Memory",
                                    "path" => "/api/v2/monitor/system/resource/usage",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $fortinetResourceUsageMapping,
                                ],
                                [
                                    "name" => "Session Statistics",
                                    "path" => "/api/v2/monitor/system/session",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $fortinetSessionStatsMapping,
                                ],
                                [
                                    "name" => "VPN Status",
                                    "path" => "/api/v2/monitor/vpn/ssl",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $fortinetVpnStatusMapping,
                                ],
                                [
                                    "name" => "Security Events (Disk Usage)",
                                    "path" => "/api/v2/monitor/log/current-disk-usage",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "storage",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $fortinetSecurityEventsMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 4. JUNIPER JUNOS (REST)
            // ---------------------------------------------------------------------
            [
                "name" => "Juniper Junos (REST)",
                "vendor" => "Juniper Networks",
                "description" => "Common REST API endpoints for Juniper Junos devices (JSON based). Requires Basic Auth (ID 2).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "System Uptime",
                                    "path" => "/rpc/get-system-uptime-information",
                                    "method" => "GET",
                                    "resource_type" => "device",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $juniperSystemUptimeMapping,
                                ],
                                [
                                    "name" => "Interface Statistics",
                                    "path" => "/api-json/op/show-interfaces-statistics",
                                    "method" => "GET",
                                    "resource_type" => "port",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $juniperInterfaceStatsMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 5. PALO ALTO PAN-OS
            // ---------------------------------------------------------------------
            [
                "name" => "Palo Alto PAN-OS",
                "vendor" => "Palo Alto Networks",
                "description" => "Standard API endpoints for Palo Alto Networks PAN-OS devices (XML based). Requires API Key Auth (ID 4).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "System Info",
                                    "path" => "/api/?type=op&cmd=<show><system><info></info></system></show>",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "device",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $paloAltoSystemInfoMapping,
                                ],
                                [
                                    "name" => "Interface Statistics",
                                    "path" => "/api/?type=op&cmd=<show><interface>all</interface></show>",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "port",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $paloAltoInterfaceStatsMapping,
                                ],
                                [
                                    "name" => "Top Applications - Networking",
                                    "path" => "/api/?type=report&reportname=top-application-categories",
                                    "method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $paloAltoTopAppsMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 6. PURE STORAGE FLASHARRAY (OAuth2 REST API 2.x)
            // ---------------------------------------------------------------------
            [
                "name" => "Pure Storage FlashArray (OAuth2 REST API 2.x)",
                "vendor" => "Pure Storage",
                "description" => "Complete Pure Storage FlashArray REST API 2.x template. Requires OAuth2 Password Flow (ID 13).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}/api/2.26",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "Array Info",
                                    "path" => "/arrays",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "device",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    "metric_map" => $pureStorageArrayInfoMapping,
                                ],
                                [
                                    "name" => "Controllers Status",
                                    "path" => "/controllers",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageControllersStatusMapping,
                                ],
                                // Added missing Volume info endpoint with proper mappings
                                [
                                    "name" => "Volumes Info",
                                    "path" => "/volumes",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "storage",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageVolumesInfoMapping,
                                ],
                                // Added missing Network Interfaces endpoint with proper mappings
                                [
                                    "name" => "Network Interfaces",
                                    "path" => "/network-interfaces",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "port",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageNetworkInterfacesMapping,
                                ],
                                // Added missing Hosts endpoint
                                [
                                    "name" => "Hosts",
                                    "path" => "/hosts",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageHostsMapping,
                                ],
                                // Added missing Array Performance endpoint
                                [
                                    "name" => "Array Performance",
                                    "path" => "/arrays/performance",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageArrayPerformanceMapping,
                                ],
                                // Added missing Volume Performance endpoint
                                [
                                    "name" => "Volume Performance",
                                    "path" => "/volumes/performance",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageVolumePerformanceMapping,
                                ],
                                // Added missing Alerts endpoint
                                [
                                    "name" => "Alerts",
                                    "path" => "/alerts",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    "resource_id_field" => "id",
                                    "resource_name_field" => "code",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageAlertsMapping,
                                ],
                                // Added missing Hardware Components endpoint
                                [
                                    "name" => "Hardware Components",
                                    "path" => "/hardware",
                                    "http_method" => "GET",
                                    "poll_interval" => 600,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageHardwareComponentsMapping,
                                ],
                                // Added missing Drives endpoint
                                [
                                    "name" => "Drives",
                                    "path" => "/drives",
                                    "http_method" => "GET",
                                    "poll_interval" => 600,
                                    "resource_type" => "storage",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    // FIXED: Using json_encode()
                                    "metric_map" => $pureStorageDrivesMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 8. PURE STORAGE FLASHARRAY (API Token Login) - ALL FIXED
            // ---------------------------------------------------------------------
            [
                "name" => "Pure Storage FlashArray (API Token Login)",
                "vendor" => "Pure Storage",
                "description" => "Template for Pure Storage FlashArray APIs using API Token exchange for a session token. Requires API Token Auth (ID 15).",
                "template_data" => [
                    "connections" => [
                        [
                            "name" => "Primary Connection",
                            "base_url" => "https://{device_hostname}",
                            "rate_limit" => 60,
                            "endpoints" => [
                                [
                                    "name" => "Array Info",
                                    "path" => "/api/2.26/arrays",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "device",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    "metric_map" => $pureStorageArrayInfoMapping,
                                ],
                                [
                                    "name" => "Controllers Status",
                                    "path" => "/api/2.26/controllers",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    "metric_map" => $pureStorageControllersStatusMapping,
                                ],
                                [
                                    "name" => "Volumes Info",
                                    "path" => "/api/2.26/volumes",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "storage",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageVolumesInfoMapping,
                                ],
                                [
                                    "name" => "Network Interfaces",
                                    "path" => "/api/2.26/network-interfaces",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "port",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageNetworkInterfacesMapping,
                                ],
                                [
                                    "name" => "Hosts",
                                    "path" => "/api/2.26/hosts",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageHostsMapping,
                                ],
                                [
                                    "name" => "Array Performance",
                                    "path" => "/api/2.26/arrays/performance",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "items.0.name",
                                    "resource_name_field" => "items.0.name",
                                    "metric_map" => $pureStorageArrayPerformanceMapping,
                                ],
                                [
                                    "name" => "Volume Performance",
                                    "path" => "/api/2.26/volumes/performance",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageVolumePerformanceMapping,
                                ],
                                [
                                    "name" => "Alerts",
                                    "path" => "/api/2.26/alerts",
                                    "http_method" => "GET",
                                    "poll_interval" => 300,
                                    "resource_type" => "custom",
                                    "resource_id_field" => "id",
                                    "resource_name_field" => "code",
                                    "metric_map" => $pureStorageAlertsMapping,
                                ],
                                [
                                    "name" => "Hardware Components",
                                    "path" => "/api/2.26/hardware",
                                    "http_method" => "GET",
                                    "poll_interval" => 600,
                                    "resource_type" => "sensor",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageHardwareComponentsMapping,
                                ],
                                [
                                    "name" => "Drives",
                                    "path" => "/api/2.26/drives",
                                    "http_method" => "GET",
                                    "poll_interval" => 600,
                                    "resource_type" => "storage",
                                    "resource_id_field" => "name",
                                    "resource_name_field" => "name",
                                    "metric_map" => $pureStorageDrivesMapping,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($templates as $template) {
            RestApiTemplate::firstOrCreate(
                ["name" => $template["name"]],
                [
                    "vendor" => $template["vendor"],
                    "template_data" => $template["template_data"],
                    "description" => $template["description"],
                ]
            );
        }
    }
}
