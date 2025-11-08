<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeviceApiTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Helper to get schema id
        $schemaId = fn($key) => DB::table('device_api_auth_schemas')->where('key', $key)->value('id');

        // Pure Storage FlashArray template
        $pureTemplate = [
            'key' => 'purestorage_flasharray',
            'label' => 'Pure Storage FlashArray (Template)',
            'os_keys' => json_encode(['purestorage']),
            'schema_id' => $schemaId('purestorage_api_token_login'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api/2.26',
                'api_login_header_key' => 'api-token',
                'login_path' => '/login',
                'auth_header_name' => 'X-Auth-Token',
                'api_session_expiry_minutes' => 30,
            ]),
            'modules' => json_encode(['sensors','inventory','ports','ipv4','storage','ports_statistics','transceivers']),
            'capabilities' => json_encode(['sensors','inventory','ports','ipv4','storage','ports_statistics','transceivers']),
            'description' => 'Login via API token to obtain session header and poll FlashArray endpoints.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];

        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $pureTemplate['key']],
            $pureTemplate
        );
        $pureTemplateId = DB::table('device_api_templates')->where('key', 'purestorage_flasharray')->value('id');

        $pureEndpoints = [
            // =========================================================================
            // 1. Array-Level Information (sensors/storage)
            // =========================================================================
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays',              'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArraySensors', 'display_order' => 10, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays/performance',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArraySensors', 'display_order' => 20, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'controllers',         'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 25, 'enabled' => 1],

            // =========================================================================
            // 2. Hardware and Inventory
            // =========================================================================
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hardware',           'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 30, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'drives',             'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 40, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'alerts',             'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureAlerts',       'display_order' => 45, 'enabled' => 1],

            // =========================================================================
            // 3. Interface and Network
            // =========================================================================
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network-interfaces',                'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeNetworkInterfaces', 'display_order' => 60, 'enabled' => 1],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'network-interfaces',                'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeIpv4',               'display_order' => 70, 'enabled' => 1],
            // Critical: Traffic rates -> ports statistics (structured for DeviceApiPersistor::savePortsStatistics)
            ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'network-interfaces/performance', 'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeNetworkPerformanceToPortsStats', 'display_order' => 100, 'enabled' => 1],
            // New: Transceiver monitoring (temperature, power)
            ['capability' => 'transceivers', 'method' => 'GET', 'path' => 'network-interfaces/port-details', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePurePortOptics', 'display_order' => 110, 'enabled' => 1],

            // =========================================================================
            // 4. Volume and Connectivity
            // =========================================================================
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes',            'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureVolumes',      'display_order' => 80, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/performance','transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureVolumes',      'display_order' => 90, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hosts',              'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHosts',        'display_order' => 50, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'connections',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureConnections',  'display_order' => 120, 'enabled' => 1],

            // Storage array details (controllers, volumes, hosts)
            ['capability' => 'storage', 'method' => 'GET', 'path' => 'arrays', 'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeStorageDetails', 'display_order' => 125, 'enabled' => 1],

            // Currently unmapped, for future extension (requires new normalizers)
            ['capability' => 'general',   'method' => 'GET', 'path' => 'arrays/performance/by-link', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePurePerformanceByLink', 'display_order' => 130, 'enabled' => 0],
            ['capability' => 'general',   'method' => 'GET', 'path' => 'array-connections',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArrayConnections','display_order' => 140, 'enabled' => 0],
            ['capability' => 'general',   'method' => 'GET', 'path' => 'subnets',            'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureSubnets',      'display_order' => 150, 'enabled' => 0],
        ];

        foreach ($pureEndpoints as $ep) {
            $existing = DB::table('device_api_template_endpoints')
                ->where('template_id', $pureTemplateId)
                ->where('capability', $ep['capability'])
                ->where('path', $ep['path'])
                ->first();

            if ($existing) {
                DB::table('device_api_template_endpoints')
                    ->where('id', $existing->id)
                    ->update(array_merge($ep, ['template_id' => $pureTemplateId, 'updated_at' => $now]));
            } else {
                DB::table('device_api_template_endpoints')->insert(
                    array_merge($ep, ['template_id' => $pureTemplateId, 'created_at' => $now, 'updated_at' => $now])
                );
            }
        }

        // Proxmox VE Node template
        $pxTemplate = [
            'key' => 'proxmox_node_default',
            'label' => 'Proxmox VE Node',
            'os_keys' => json_encode(['proxmox']),
            'schema_id' => $schemaId('proxmox_token'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}:8006/api2/json',
                'api_token_user' => 'user@pve',
                'api_token_id'   => 'tokenid',
            ]),
            'modules' => json_encode(['sensors','mempools','processors','ports','inventory','ports_statistics']),
            'capabilities' => json_encode(['sensors','mempools','processors','ports','inventory','ipv4','ports_statistics']),
            'description' => 'Proxmox node endpoints for status, network, storage.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $pxTemplate['key']], $pxTemplate);
        $pxTemplateId = DB::table('device_api_templates')->where('key', 'proxmox_node_default')->value('id');

        $pxEndpoints = [
            ['capability' => 'sensors',    'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 10],
            ['capability' => 'mempools',   'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 20],
            ['capability' => 'processors', 'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 30],
            ['capability' => 'ports',      'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeNetwork',   'display_order' => 40],
            ['capability' => 'inventory',  'method' => 'GET', 'path' => 'nodes/{node}/storage', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStorage',   'display_order' => 50],
            ['capability' => 'ipv4',       'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxIpv4',          'display_order' => 60],
            ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'nodes/{node}/rrddata?timeframe=hour', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNetworkStatistics', 'display_order' => 70],
        ];
        foreach ($pxEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $pxTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $pxTemplateId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Fortinet FortiGate
        $fortiTpl = [
            'key' => 'fortinet_fortigate',
            'label' => 'Fortinet FortiGate',
            'os_keys' => json_encode(['fortigate']),
            'schema_id' => $schemaId('bearer'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api/v2',
            ]),
            'modules' => json_encode(['sensors','inventory','ports','ipv4']),
            'capabilities' => json_encode(['sensors','inventory','ports','ipv4']),
            'description' => 'FortiGate REST v2 API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $fortiTpl['key']], $fortiTpl);
        $fortiTplId = DB::table('device_api_templates')->where('key', 'fortinet_fortigate')->value('id');
        $fortiEndpoints = [
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/resource/usage', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateSystemUsage', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'monitor/system/status',         'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateSystemStatus','display_order' => 20],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'monitor/system/interface',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateInterfaces', 'display_order' => 30],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'monitor/system/interface',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateIpv4',       'display_order' => 40],
            // Hardware sensors (temperature, fan, voltage, power)
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/sensor-info',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateSensorInfo',    'display_order' => 50],
            // VPN monitoring
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ipsec',             'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateVpnIpsec',     'display_order' => 60],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ssl',               'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateVpnSsl',       'display_order' => 70],
            // DHCP and licensing
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/dhcp',           'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateDhcp',          'display_order' => 80],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/license/status',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateLicense',       'display_order' => 90],
        ];
        foreach ($fortiEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $fortiTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $fortiTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Juniper Junos (RPC/REST)
        $junosTpl = [
            'key' => 'juniper_junos',
            'label' => 'Juniper Junos',
            'os_keys' => json_encode(['junos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rpc',
            ]),
            'modules' => json_encode(['sensors','inventory','ports']),
            'capabilities' => json_encode(['sensors','inventory','ports']),
            'description' => 'Junos RPC/REST endpoints for system, chassis, interface info',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $junosTpl['key']], $junosTpl);
        $junosTplId = DB::table('device_api_templates')->where('key', 'juniper_junos')->value('id');
        $junosEndpoints = [
            ['capability' => 'ports',     'method' => 'POST', 'path' => 'get-interface-information', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosInterfaces', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'get-chassis-inventory',     'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosInventory',  'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'POST', 'path' => 'get-system-information',     'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosSystem',     'display_order' => 30],
        ];
        foreach ($junosEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $junosTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, [
                    'template_id' => $junosTplId,
                    'enabled' => 1,
                    'headers' => json_encode(['Content-Type' => 'application/xml']),
                    'created_at' => $now,
                    'updated_at' => $now
                ])
            );
        }

        // Dell OS10/Force10 (N-Series)
        $dellTpl = [
            'key' => 'dell_os10',
            'label' => 'Dell OS10',
            'os_keys' => json_encode(['dell-os10']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rest/v1',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Dell OS10 REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $dellTpl['key']], $dellTpl);
        $dellTplId = DB::table('device_api_templates')->where('key', 'dell_os10')->value('id');
        $dellEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellSystem',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',   'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellSensors',   'display_order' => 30],
        ];
        foreach ($dellEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $dellTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $dellTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // HPE Comware / Aruba CX
        $hpeTpl = [
            'key' => 'hpe_network',
            'label' => 'HPE / Aruba Network OS',
            'os_keys' => json_encode(['comware', 'arubaos-cx']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rest',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'HPE Comware / Aruba REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $hpeTpl['key']], $hpeTpl);
        $hpeTplId = DB::table('device_api_templates')->where('key', 'hpe_network')->value('id');
        $hpeEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeHpeSystem',     'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeHpeInterfaces', 'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeHpeSensors',    'display_order' => 30],
        ];
        foreach ($hpeEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $hpeTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $hpeTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // HPE NimbleOS Storage
        $nimbleTpl = [
            'key' => 'hpe_nimble',
            'label' => 'HPE NimbleOS',
            'os_keys' => json_encode(['nimbleos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/v1',
            ]),
            'modules' => json_encode(['inventory','sensors','ports']),
            'capabilities' => json_encode(['inventory','sensors','ports']),
            'description' => 'HPE NimbleOS REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $nimbleTpl['key']], $nimbleTpl);
        $nimbleTplId = DB::table('device_api_templates')->where('key', 'hpe_nimble')->value('id');
        $nimbleEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'arrays',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleArrays',     'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'disks',         'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleDisks',      'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/stats', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleStats',      'display_order' => 30],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleInterfaces', 'display_order' => 40],
        ];
        foreach ($nimbleEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nimbleTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nimbleTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Nutanix Prism
        $nutanixTpl = [
            'key' => 'nutanix_prism',
            'label' => 'Nutanix Prism',
            'os_keys' => json_encode(['nutanix-aos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}:9440/api/nutanix/v3',
            ]),
            'modules' => json_encode(['inventory','sensors']),
            'capabilities' => json_encode(['inventory','sensors']),
            'description' => 'Nutanix Prism v3 API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $nutanixTpl['key']], $nutanixTpl);
        $nutanixTplId = DB::table('device_api_templates')->where('key', 'nutanix_prism')->value('id');
        $nutanixEndpoints = [
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'clusters/list',     'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNutanixClusters', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json'])],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'hosts/list',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNutanixHosts',    'display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json'])],
        ];
        foreach ($nutanixEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nutanixTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nutanixTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now, 'body' => json_encode(['kind' => 'cluster'])])
            );
        }

        // NetApp ONTAP Template
        $netappTemplate = [
            'key' => 'netapp_ontap',
            'label' => 'NetApp ONTAP API',
            'os_keys' => json_encode(['netapp']),
            'schema_id' => $schemaId('basic'), // Corrected from 'basic_auth' to 'basic'
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api',
            ]),
            'modules' => json_encode(['inventory', 'ports', 'ipv4', 'storage', 'sensors', 'ports_statistics']),
            'capabilities' => json_encode(['inventory', 'ports', 'ipv4', 'storage', 'sensors', 'ports_stats']),
            'description' => 'Template for NetApp ONTAP REST API, providing discovery and metrics for ports, storage, and inventory.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];

        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $netappTemplate['key']],
            $netappTemplate
        );

        $templateId = DB::table('device_api_templates')->where('key', 'netapp_ontap')->value('id');

        // Define Endpoints
        $endpoints = [
            [
                'template_id' => $templateId,
                'path' => '/cluster/nodes',
                'http_method' => 'GET',
                'capability' => 'inventory',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizeClusterNodes',
                'cache_ttl_seconds' => 3600,
                'display_order' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ethernet/ports',
                'http_method' => 'GET',
                'capability' => 'ports',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizeNetworkPorts',
                'cache_ttl_seconds' => 3600,
                'display_order' => 20,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ip/interfaces',
                'http_method' => 'GET',
                'capability' => 'ipv4',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizeIpv4',
                'cache_ttl_seconds' => 3600,
                'display_order' => 30,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/storage/volumes',
                'http_method' => 'GET',
                'capability' => 'storage',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizeVolumes',
                'cache_ttl_seconds' => 900,
                'display_order' => 40,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/cluster/nodes?fields=statistics.processor_utilization_raw',
                'http_method' => 'GET',
                'capability' => 'sensors',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizeClusterMetrics',
                'cache_ttl_seconds' => 300,
                'display_order' => 50,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ethernet/ports/{port_uuid}/metrics',
                'http_method' => 'GET',
                'capability' => 'ports_stats',
                'transform_class' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer',
                'transform_method' => 'normalizePortMetrics',
                'cache_ttl_seconds' => 0, // Do not cache metrics
                'display_order' => 60,
                'for_each' => 'ports', // Tells executor to iterate over discovered ports
                'for_each_options' => json_encode(['placeholder' => 'port_uuid', 'value_key' => 'uuid']),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($endpoints as $endpoint) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $endpoint['template_id'], 'path' => $endpoint['path']],
                $endpoint
            );
        }
    }
}