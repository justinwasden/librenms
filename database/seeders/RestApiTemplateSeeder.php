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
        $templates = [
            // ---------------------------------------------------------------------
            // 1. ARUBA CX
            // ---------------------------------------------------------------------
            [
                'name' => 'Aruba CX',
                'vendor' => 'Aruba',
                'description' => 'Standard REST API endpoints for Aruba CX switches (JSON based). Requires Basic Auth (ID 2).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}/rest',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'System Info',
                                    'path' => '/v10.04/system',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'hostname' => 'hostname',
                                        'uptime' => 'boot_time',
                                        'version' => 'software_version',
                                    ],
                                ],
                                [
                                    'name' => 'Interface Statistics',
                                    'path' => '/v10.04/system/interfaces/*/statistics',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'rx_bytes' => 'rx_bytes',
                                        'tx_bytes' => 'tx_bytes',
                                        'rx_packets' => 'rx_packets',
                                        'tx_packets' => 'tx_packets',
                                    ],
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
                'name' => 'Cisco IOS XE (RESTCONF)',
                'vendor' => 'Cisco',
                'description' => 'Common RESTCONF API endpoints for Cisco IOS XE devices (JSON based). Requires Basic Auth (ID 2).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'CPU Utilization',
                                    'path' => '/restconf/data/Cisco-IOS-XE-process-cpu-oper:cpu-usage/cpu-utilization',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'five_sec_avg' => 'cpu-utilization.five-seconds-average',
                                        'one_min_avg' => 'cpu-utilization.one-minute-average',
                                        'five_min_avg' => 'cpu-utilization.five-minutes-average',
                                    ],
                                ],
                                [
                                    'name' => 'Memory Statistics',
                                    'path' => '/restconf/data/Cisco-IOS-XE-memory-oper:memory-statistics',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'total_memory' => 'memory-statistics.total-memory',
                                        'used_memory' => 'memory-statistics.used-memory',
                                        'free_memory' => 'memory-statistics.free-memory',
                                    ],
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
                'name' => 'Fortinet FortiGate',
                'vendor' => 'Fortinet',
                'description' => 'Common API endpoints for Fortinet FortiGate devices (JSON based). Requires Token/Bearer Auth (ID 3).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}',
                            'rate_limit' => 120,
                            'endpoints' => [
                                [
                                    'name' => 'System Status',
                                    'path' => '/api/v2/monitor/system/status',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'hostname' => 'results.hostname',
                                        'firmware_version' => 'results.version',
                                        'model_name' => 'results.model_name',
                                        'serial_number' => 'results.serial',
                                        'status' => 'status',
                                    ],
                                ],
                                [
                                    'name' => 'CPU and Memory',
                                    'path' => '/api/v2/monitor/system/resource/usage',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'cpu_current' => 'results.cpu.0.current',
                                        'cpu_average_1min' => 'results.cpu.0.historical.1-min.average',
                                        'mem_current' => 'results.mem.0.current',
                                        'mem_average_1min' => 'results.mem.0.historical.1-min.average',
                                        'disk_current' => 'results.disk.0.current',
                                        'disk_average_1min' => 'results.disk.0.historical.1-min.average',
                                    ],
                                ],
                                [
                                    'name' => 'Session Statistics',
                                    'path' => '/api/v2/monitor/system/session',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'session_current' => 'session.0.current',
                                        'session_average_1min' => 'session.0.historical.1-min.average',
                                    ],
                                ],
                                [
                                    'name' => 'VPN Status',
                                    'path' => '/api/v2/monitor/vpn/ssl',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'vpn_users_active' => 'results.0.users',
                                    ],
                                ],
                                [
                                    'name' => 'Security Events (Disk Usage)',
                                    'path' => '/api/v2/monitor/log/current-disk-usage',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'log_disk_used_bytes' => 'results.used_bytes',
                                        'log_disk_free_bytes' => 'results.free_bytes',
                                        'log_disk_total_bytes' => 'results.total_bytes',
                                    ],
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
                'name' => 'Juniper Junos (REST)',
                'vendor' => 'Juniper Networks',
                'description' => 'Common REST API endpoints for Juniper Junos devices (JSON based). Requires Basic Auth (ID 2).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'System Uptime',
                                    'path' => '/rpc/get-system-uptime-information',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'uptime_seconds' => 'system-uptime-information.up-time.seconds',
                                    ],
                                ],
                                [
                                    'name' => 'Interface Statistics',
                                    'path' => '/api-json/op/show-interfaces-statistics',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'ge-0/0/0_rx_bytes' => 'interface-statistics.physical-interface.0.input-bytes',
                                        'ge-0/0/0_tx_bytes' => 'interface-statistics.physical-interface.0.output-bytes',
                                    ],
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
                'name' => 'Palo Alto PAN-OS',
                'vendor' => 'Palo Alto Networks',
                'description' => 'Standard API endpoints for Palo Alto Networks PAN-OS devices (XML based). Requires API Key Auth (ID 4).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'System Info',
                                    'path' => '/api/?type=op&cmd=<show><system><info></info></system></show>',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'hostname' => 'result.system.hostname',
                                        'uptime' => 'result.system.uptime',
                                        'version' => 'result.system.sw-version',
                                    ],
                                ],
                                [
                                    'name' => 'Interface Statistics',
                                    'path' => '/api/?type=op&cmd=<show><interface>all</interface></show>',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'ethernet1/1_rx_bytes' => 'result.interface.ethernet1/1.stats.ibytes',
                                        'ethernet1/1_tx_bytes' => 'result.interface.ethernet1/1.stats.obytes',
                                    ],
                                ],
                                [
                                    'name' => 'Top Applications - Networking',
                                    'path' => '/api/?type=report&reportname=top-application-categories',
                                    'method' => 'GET',
                                    'poll_interval' => 300,
                                    'metric_map' => [
                                        'report_name' => 'report.@attributes.reportname',
                                        'result_name' => 'report.result.@attributes.name',
                                        'networking_category_name' => 'report.result.entry.0.category-of-name',
                                        'networking_sessions' => 'report.result.entry.0.nsess',
                                        'networking_bytes' => 'report.result.entry.0.nbytes',
                                    ],
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
                'name' => 'Pure Storage FlashArray (OAuth2 REST API 2.x)',
                'vendor' => 'Pure Storage',
                'description' => 'Complete Pure Storage FlashArray REST API 2.x template. Requires OAuth2 Password Flow (ID 13).',
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
                                    'resource_type' => 'array',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'total_capacity' => 'items.0.space.total_physical',
                                        'used_capacity' => 'items.0.space.total_used',
                                        'available_capacity' => 'items.0.space.total_provisioned',
                                        'array_data_reduction' => 'items.0.space.data_reduction',
                                        'array_total_reduction' => 'items.0.space.total_reduction',
                                        'array_capacity' => 'items.0.capacity',
                                        'array_name' => 'items.0.name',
                                        'array_id' => 'items.0.id',
                                        'array_version' => 'items.0.version',
                                    ],
                                ],
                                [
                                    'name' => 'Controllers Status',
                                    'path' => '/controllers',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'controller',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'controller_name' => 'items.0.name',
                                        'controller_model' => 'items.0.model',
                                        'controller_status' => 'items.0.status',
                                        'controller_mode' => 'items.0.mode',
                                        'purity_version' => 'items.0.version',
                                    ],
                                ],
                                [
                                    'name' => 'Volumes Info',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'volume',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'volume_name' => 'name',
                                        'volume_provisioned' => 'provisioned',
                                        'volume_snapshots' => 'space.snapshots',
                                        'volume_data_reduction' => 'space.data_reduction',
                                        'volume_total_reduction' => 'space.total_reduction',
                                        'volume_connections' => 'connection_count',
                                        'volume_group' => 'volume_group.name',
                                        'volume_pod' => 'pod.name',
                                        'volume_created' => 'created',
                                        'volume_serial' => 'serial',
                                    ],
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/network-interfaces',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'ifName' => 'name',
                                        'ifPhysAddress' => 'eth.mac_address',
                                        'ifAdminStatus' => 'enabled',
                                        'ifOperStatus' => 'enabled',
                                        'ifSpeed' => 'speed',
                                        'ifMtu' => 'eth.mtu',
                                        'ifType' => 'interface_type',
                                        'port_descr_type' => 'services',
                                        'ipv4_address' => 'eth.address',
                                        'ipv4_netmask' => 'eth.netmask',
                                        'ipv4_gateway' => 'eth.gateway',
                                        'fc_wwn' => 'fc.wwn',
                                        'ifVlan' => 'eth.vlan',
                                    ],
                                ],
                                [
                                    'name' => 'Hosts',
                                    'path' => '/hosts',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'host',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'host_name' => 'name',
                                        'host_group' => 'host_group.name',
                                        'host_connections' => 'connection_count',
                                        'host_connection_status' => 'port_connectivity.status',
                                        'host_totalspace' => 'space.total_physical',
                                        'host_total_reduction' => 'space.total_reduction',
                                    ],
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/arrays/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'array_performance',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'array_name' => 'items.0.name',
                                        'array_read_bytes_per_sec' => 'items.0.read_bytes_per_sec',
                                        'array_write_bytes_per_sec' => 'items.0.write_bytes_per_sec',
                                        'array_usec_per_read_op' => 'items.0.usec_per_read_op',
                                        'array_reads_per_sec' => 'items.0.reads_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/volumes/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'volume_performance',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'volume_name' => 'name',
                                        'volume_read_bytes_per_sec' => 'read_bytes_per_sec',
                                        'volume_write_bytes_per_sec' => 'write_bytes_per_sec',
                                        'volume_usec_per_read_op' => 'usec_per_read_op',
                                        'volume_reads_per_sec' => 'reads_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Alerts',
                                    'path' => '/alerts',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'alert',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'code',
                                    'response_mapping' => [
                                        'alert_id' => 'id',
                                        'alert_state' => 'state',
                                        'alert_code' => 'code',
                                        'alert_severity' => 'severity',
                                        'alert_created' => 'created',
                                        'alert_summary' => 'summary',
                                    ],
                                ],
                                [
                                    'name' => 'Hardware Components',
                                    'path' => '/hardware',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'hardware',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'hardware_name' => 'name',
                                        'hardware_status' => 'status',
                                        'hardware_type' => 'type',
                                        'hardware_serial' => 'serial',
                                        'hardware_temperature' => 'temperature',
                                    ],
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/drives',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'drive',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'drive_name' => 'name',
                                        'drive_status' => 'status',
                                        'drive_type' => 'type',
                                        'drive_capacity' => 'capacity',
                                        'drive_protocol' => 'protocol',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 7. PURE STORAGE PURE1 (Cloud API)
            // ---------------------------------------------------------------------
            [
                'name' => 'Pure Storage Pure1 (Cloud API)',
                'vendor' => 'Pure Storage',
                'description' => 'API endpoints for Pure Storage Pure1 Cloud API (JSON based). Requires Custom Auth (ID 14).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://api.pure1.purestorage.com/api/2.0',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'Pure1 Arrays Info',
                                    'path' => '/arrays',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_array',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'id' => 'id', 'name' => 'name', 'model' => 'model', 'version' => 'version', 'status' => 'status',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Array Metrics',
                                    'path' => '/arrays/{array_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_array_metrics',
                                    'resource_id_field' => 'array_id',
                                    'resource_name_field' => 'array_id',
                                    'response_mapping' => [
                                        'bytes_per_sec' => 'bytes_per_sec', 'reads_per_sec' => 'reads_per_sec', 'writes_per_sec' => 'writes_per_sec', 'data_reduction' => 'data_reduction',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Hardware',
                                    'path' => '/arrays/{array_id}/hardware',
                                    'http_method' => 'GET',
                                    'poll_interval' => 3600,
                                    'resource_type' => 'pure1_hardware',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'first_component_name' => 'items.0.name',
                                        'first_component_status' => 'items.0.status',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Volumes',
                                    'path' => '/volumes',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_volume',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'volume_id' => 'id', 'volume_name' => 'name', 'volume_provisioned' => 'provisioned', 'volume_used' => 'used',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Volume Performance',
                                    'path' => '/volumes/{volume_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_volume_metrics',
                                    'resource_id_field' => 'volume_id',
                                    'resource_name_field' => 'volume_id',
                                    'response_mapping' => [
                                        'volume_reads_per_sec' => 'reads_per_sec', 'volume_writes_per_sec' => 'writes_per_sec', 'volume_latency_usec' => 'usec_per_op',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Hosts',
                                    'path' => '/hosts',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_host',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'host_id' => 'id', 'host_name' => 'name', 'host_os' => 'os',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Host Performance',
                                    'path' => '/hosts/{host_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_host_metrics',
                                    'resource_id_field' => 'host_id',
                                    'resource_name_field' => 'host_id',
                                    'response_mapping' => [
                                        'host_reads_per_sec' => 'reads_per_sec', 'host_writes_per_sec' => 'writes_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Pods',
                                    'path' => '/pods',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_pod',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'pod_id' => 'id', 'pod_name' => 'name', 'pod_status' => 'status',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Pod Performance',
                                    'path' => '/pods/{pod_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_pod_metrics',
                                    'resource_id_field' => 'pod_id',
                                    'resource_name_field' => 'pod_id',
                                    'response_mapping' => [
                                        'pod_reads_per_sec' => 'reads_per_sec', 'pod_writes_per_sec' => 'writes_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 File Systems',
                                    'path' => '/file-systems',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_fs',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'fs_id' => 'id', 'fs_name' => 'name', 'fs_total_space' => 'total_space', 'fs_used_space' => 'used_space',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 File System Performance',
                                    'path' => '/file-systems/{file_system_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_fs_metrics',
                                    'resource_id_field' => 'file_system_id',
                                    'resource_name_field' => 'file_system_id',
                                    'response_mapping' => [
                                        'fs_reads_per_sec' => 'reads_per_sec', 'fs_writes_per_sec' => 'writes_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Directories',
                                    'path' => '/file-systems/{file_system_id}/directories',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_directory',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'dir_id' => 'id', 'dir_name' => 'name', 'dir_size' => 'size',
                                    ],
                                ],
                                [
                                    'name' => 'Pure1 Directory Performance',
                                    'path' => '/file-systems/{file_system_id}/directories/{directory_id}/metrics',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'pure1_directory_metrics',
                                    'resource_id_field' => 'directory_id',
                                    'resource_name_field' => 'directory_id',
                                    'response_mapping' => [
                                        'dir_reads_per_sec' => 'reads_per_sec', 'dir_writes_per_sec' => 'writes_per_sec',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------------
            // 8. PURE STORAGE FLASHARRAY (API Token Login)
            // ---------------------------------------------------------------------
            [
                'name' => 'Pure Storage FlashArray (API Token Login)',
                'vendor' => 'Pure Storage',
                'description' => 'Template for Pure Storage FlashArray APIs using API Token exchange for a session token. Requires API Token Auth (ID 15).',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Primary Connection',
                            'base_url' => 'https://{device_hostname}',
                            'rate_limit' => 60,
                            'endpoints' => [
                                [
                                    'name' => 'Array Info',
                                    'path' => '/api/2.26/arrays',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'array',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'total_capacity' => 'items.0.space.total_physical',
                                        'used_capacity' => 'items.0.space.total_used',
                                        'available_capacity' => 'items.0.space.total_provisioned',
                                        'array_data_reduction' => 'items.0.space.data_reduction',
                                        'array_total_reduction' => 'items.0.space.total_reduction',
                                        'array_capacity' => 'items.0.capacity',
                                        'array_name' => 'items.0.name',
                                        'array_id' => 'items.0.id',
                                        'array_version' => 'items.0.version',
                                    ],
                                ],
                                [
                                    'name' => 'Controllers Status',
                                    'path' => '/api/2.26/controllers',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'controller',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'controller_name' => 'items.0.name',
                                        'controller_model' => 'items.0.model',
                                        'controller_status' => 'items.0.status',
                                        'controller_mode' => 'items.0.mode',
                                        'purity_version' => 'items.0.version',
                                    ],
                                ],
                                [
                                    'name' => 'Volumes Info',
                                    'path' => '/api/2.26/volumes',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'volume',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'volume_name' => 'name',
                                        'volume_provisioned' => 'provisioned',
                                        'volume_snapshots' => 'space.snapshots',
                                        'volume_data_reduction' => 'space.data_reduction',
                                        'volume_total_reduction' => 'space.total_reduction',
                                        'volume_connections' => 'connection_count',
                                        'volume_group' => 'volume_group.name',
                                        'volume_pod' => 'pod.name',
                                        'volume_created' => 'created',
                                        'volume_serial' => 'serial',
                                    ],
                                ],
                                [
                                    'name' => 'Network Interfaces',
                                    'path' => '/api/2.26/network-interfaces',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'interface',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'ifName' => 'name',
                                        'ifPhysAddress' => 'eth.mac_address',
                                        'ifAdminStatus' => 'enabled',
                                        'ifOperStatus' => 'enabled',
                                        'ifSpeed' => 'speed',
                                        'ifMtu' => 'eth.mtu',
                                        'ifType' => 'interface_type',
                                        'ipv4_address' => 'eth.address',
                                        'ifVlan' => 'eth.vlan',
                                    ],
                                ],
                                [
                                    'name' => 'Hosts',
                                    'path' => '/api/2.26/hosts',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'host',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'host_name' => 'name',
                                        'host_group' => 'host_group.name',
                                        'host_connections' => 'connection_count',
                                        'host_connection_status' => 'port_connectivity.status',
                                        'host_totalspace' => 'space.total_physical',
                                        'host_total_reduction' => 'space.total_reduction',
                                    ],
                                ],
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/api/2.26/arrays/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'array_performance',
                                    'resource_id_field' => 'items.0.name',
                                    'resource_name_field' => 'items.0.name',
                                    'response_mapping' => [
                                        'array_name' => 'items.0.name',
                                        'array_read_bytes_per_sec' => 'items.0.read_bytes_per_sec',
                                        'array_writes_per_sec' => 'items.0.writes_per_sec',
                                        'array_usec_per_read_op' => 'items.0.usec_per_read_op',
                                        'array_reads_per_sec' => 'items.0.reads_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Volume Performance',
                                    'path' => '/api/2.26/volumes/performance',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'volume_performance',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'volume_name' => 'name',
                                        'volume_read_bytes_per_sec' => 'read_bytes_per_sec',
                                        'volume_writes_per_sec' => 'write_bytes_per_sec',
                                        'volume_usec_per_read_op' => 'usec_per_read_op',
                                        'volume_reads_per_sec' => 'reads_per_sec',
                                    ],
                                ],
                                [
                                    'name' => 'Alerts',
                                    'path' => '/api/2.26/alerts',
                                    'http_method' => 'GET',
                                    'poll_interval' => 300,
                                    'resource_type' => 'alert',
                                    'resource_id_field' => 'id',
                                    'resource_name_field' => 'code',
                                    'response_mapping' => [
                                        'alert_id' => 'id',
                                        'alert_state' => 'state',
                                        'alert_severity' => 'severity',
                                        'alert_created' => 'created',
                                    ],
                                ],
                                [
                                    'name' => 'Hardware Components',
                                    'path' => '/api/2.26/hardware',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'hardware',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'hardware_name' => 'name',
                                        'hardware_status' => 'status',
                                        'hardware_type' => 'type',
                                        'hardware_serial' => 'serial',
                                        'hardware_temperature' => 'temperature',
                                    ],
                                ],
                                [
                                    'name' => 'Drives',
                                    'path' => '/api/2.26/drives',
                                    'http_method' => 'GET',
                                    'poll_interval' => 600,
                                    'resource_type' => 'drive',
                                    'resource_id_field' => 'name',
                                    'resource_name_field' => 'name',
                                    'response_mapping' => [
                                        'drive_name' => 'name',
                                        'drive_status' => 'status',
                                        'drive_type' => 'type',
                                        'drive_capacity' => 'capacity',
                                        'drive_protocol' => 'protocol',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($templates as $template) {
            RestApiTemplate::firstOrCreate(
                ['name' => $template['name']],
                [
                    'vendor' => $template['vendor'],
                    'template_data' => $template['template_data'],
                    'description' => $template['description'],
                ]
            );
        }
    }
}