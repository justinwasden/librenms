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
            'modules' => json_encode(['sensors','inventory','ports','ipv4','storage','ports_statistics','transceivers','device_info']),
            'capabilities' => json_encode(['sensors','inventory','ports','ipv4','storage','ports_statistics','transceivers','device_info']),
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
            // 0. Device Information (hardware, serial, sysObjectID, etc.)
            // =========================================================================
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'arrays',          'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureDeviceInfo', 'display_order' => 5, 'enabled' => 1],

            // =========================================================================
            // 1. Array-Level Information (sensors/storage)
            // =========================================================================
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays',          'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArraySensors', 'display_order' => 10, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays/performance',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArraySensors', 'display_order' => 20, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'controllers',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 25, 'enabled' => 1],

            // =========================================================================
            // 2. Hardware and Inventory
            // =========================================================================
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hardware',          'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 30, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'drives',            'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHardware',     'display_order' => 40, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'alerts',            'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureAlerts',        'display_order' => 45, 'enabled' => 1],

            // =========================================================================
            // 3. Interface and Network
            // =========================================================================
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network-interfaces',             'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeNetworkInterfaces', 'display_order' => 60, 'enabled' => 1],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'network-interfaces',             'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeIpv4',              'display_order' => 70, 'enabled' => 1],
            ['capability' => 'vlans',     'method' => 'GET', 'path' => 'network-interfaces',             'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeVlans',             'display_order' => 75, 'enabled' => 1],
            // Critical: Traffic rates -> ports statistics (structured for DeviceApiPersistor::savePortsStatistics)
            ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'network-interfaces/performance', 'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeNetworkPerformanceToPortsStats', 'display_order' => 100, 'enabled' => 1],
            // New: Transceiver monitoring (temperature, power)
            ['capability' => 'transceivers', 'method' => 'GET', 'path' => 'network-interfaces/port-details', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePurePortOptics', 'display_order' => 110, 'enabled' => 1],

            // =========================================================================
            // 4. Volume and Connectivity
            // =========================================================================
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes',           'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureVolumes',       'display_order' => 80, 'enabled' => 1],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/performance','transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureVolumes',       'display_order' => 90, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hosts',             'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureHosts',         'display_order' => 50, 'enabled' => 0],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'connections',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureConnections',   'display_order' => 120, 'enabled' => 0],

            // Storage array details (controllers, volumes, hosts)
            ['capability' => 'storage', 'method' => 'GET', 'path' => 'arrays', 'transform' => '\LibreNMS\Util\Normalizers\PureStorageNormalizer::normalizeStorageDetails', 'display_order' => 125, 'enabled' => 1],

            // Currently unmapped, for future extension (requires new normalizers)
            ['capability' => 'general',   'method' => 'GET', 'path' => 'arrays/performance/by-link', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePurePerformanceByLink', 'display_order' => 130, 'enabled' => 0],
            ['capability' => 'general',   'method' => 'GET', 'path' => 'array-connections',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureArrayConnections','display_order' => 140, 'enabled' => 0],
            ['capability' => 'general',   'method' => 'GET', 'path' => 'subnets',            'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizePureSubnets',       'display_order' => 150, 'enabled' => 0],
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

        // =========================================================================
        // Proxmox VE Node template (UPDATED)
        // =========================================================================
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
            // Added 'storage', 'discovery', 'vminfo', 'clusters', and 'hypervisor_hosts' modules per blueprint
            'modules' => json_encode(['sensors','mempools','processors','ports','inventory','ports_statistics','device_info','storage','discovery','vminfo','clusters','hypervisor_hosts']),
            // Added 'storage', 'discovery', 'vminfo', 'clusters', and 'hypervisor_hosts' capabilities per blueprint
            'capabilities' => json_encode(['sensors','mempools','processors','ports','inventory','ipv4','ports_statistics','device_info','storage','discovery','vminfo','clusters','hypervisor_hosts']),
            'description' => 'Proxmox node endpoints for status, network, storage, and guest discovery.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $pxTemplate['key']], $pxTemplate);
        $pxTemplateId = DB::table('device_api_templates')->where('key', 'proxmox_node_default')->value('id');

        // Updated endpoints based on blueprint
        $pxEndpoints = [
            // === Blueprint II.A: Core Host Metrics (CPU, Mem, Uptime) ===
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxDeviceInfo',    'display_order' => 5],
            ['capability' => 'sensors',     'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 10], // For Uptime
            ['capability' => 'mempools',    'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 20],
            ['capability' => 'processors',  'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStatus',    'display_order' => 30],

            // === Blueprint II.C: Host Network (Discovery) ===
            ['capability' => 'ports',       'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeNetwork',   'display_order' => 40],
            ['capability' => 'ipv4',        'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxIpv4',         'display_order' => 45],

            // === Blueprint II.B: Host Storage (Discovery) ===
            ['capability' => 'inventory',   'method' => 'GET', 'path' => 'nodes/{node}/storage', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodeStorage',   'display_order' => 50],

            // === Blueprint II.D: Host Hardware S.M.A.R.T. (Discovery) ===
            ['capability' => 'inventory',   'method' => 'GET', 'path' => 'nodes/{node}/disks/list', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxDiskList', 'display_order' => 55],

            // === Blueprint III.A / IV.C: Guest VM/LXC (Discovery) ===
            ['capability' => 'vminfo',   'method' => 'GET', 'path' => 'cluster/resources?type=vm', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxGuestDiscovery', 'display_order' => 60],

            // === Cluster and Node Information ===
            ['capability' => 'clusters',   'method' => 'GET', 'path' => 'cluster/status', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxClusterInfo', 'display_order' => 65],
            ['capability' => 'hypervisor_hosts',   'method' => 'GET', 'path' => 'nodes', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNodes', 'display_order' => 66],

            // === POLLING ENDPOINTS (Looping / Rate) ===

            // === Blueprint II.B: Host Storage (Polling) ===
            // Polls /nodes/{node}/storage/{storageid}/status for each item from 'nodes/{node}/storage' inventory
            [
                'capability' => 'storage',
                'method' => 'GET',
                'path' => 'nodes/{node}/storage/{storageid}/status',
                'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxStorageStatus',
                'display_order' => 70,
                'for_each' => 'inventory',
                'for_each_options' => json_encode(['placeholder' => 'storageid', 'value_key' => 'storage', 'filter_key' => 'storage']), // Runs for inventory items that have a 'storage' key
            ],

            // === Blueprint II.D: Host Hardware S.M.A.R.T. (Polling) ===
            // Polls /nodes/{node}/disks/smart for each item from 'nodes/{node}/disks/list' inventory
            [
                'capability' => 'sensors',
                'method' => 'GET',
                'path' => 'nodes/{node}/disks/smart?disk={devpath}',
                'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxDiskSmart',
                'display_order' => 80,
                'for_each' => 'inventory',
                'for_each_options' => json_encode(['placeholder' => 'devpath', 'value_key' => 'devpath', 'filter_key' => 'devpath']), // Runs for inventory items that have a 'devpath' key
            ],

            // === Blueprint II.C: Host Network (Polling - The Compromise) ===
            // Enabled and added timeframe=hour per blueprint
            [
                'capability' => 'ports_statistics',
                'method' => 'GET',
                'path' => 'nodes/{node}/rrddata?timeframe=hour',
                'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeProxmoxNetworkStatistics',
                'display_order' => 90,
                'enabled' => 1
            ],
        ];

        foreach ($pxEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $pxTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $pxTemplateId, 'enabled' => $ep['enabled'] ?? 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Also update the legacy proxmox_ve_token template with the same endpoints
        $legacyPxTemplateId = DB::table('device_api_templates')->where('key', 'proxmox_ve_token')->value('id');
        if ($legacyPxTemplateId) {
            // Update the template capabilities and modules
            DB::table('device_api_templates')
                ->where('id', $legacyPxTemplateId)
                ->update([
                    'modules' => $pxTemplate['modules'],
                    'capabilities' => $pxTemplate['capabilities'],
                    'updated_at' => $now,
                ]);

            // Add all the new endpoints to the legacy template
            foreach ($pxEndpoints as $ep) {
                DB::table('device_api_template_endpoints')->updateOrInsert(
                    ['template_id' => $legacyPxTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                    array_merge($ep, ['template_id' => $legacyPxTemplateId, 'enabled' => $ep['enabled'] ?? 1, 'created_at' => $now, 'updated_at' => $now])
                );
            }
        }

        // =========================================================================
        // Fortinet FortiGate
        // =========================================================================
        $fortiTpl = [
            'key' => 'fortinet_fortigate',
            'label' => 'Fortinet FortiGate',
            'os_keys' => json_encode(['fortigate']),
            'schema_id' => $schemaId('bearer'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api/v2',
            ]),
            'modules' => json_encode(['sensors','inventory','ports','ipv4','device_info','ports_statistics','vlans','routes']),
            'capabilities' => json_encode(['sensors','inventory','ports','ipv4','device_info','ports_statistics','vlans','routes']),
            'description' => 'FortiGate REST v2 API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $fortiTpl['key']], $fortiTpl);
        $fortiTplId = DB::table('device_api_templates')->where('key', 'fortinet_fortigate')->value('id');
        $fortiEndpoints = [
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'monitor/system/status',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateDeviceInfo', 'display_order' => 5],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/resource/usage', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateSystemUsage', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'monitor/system/status',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateSystemStatus','display_order' => 20],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'monitor/system/interface',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateInterfaces', 'display_order' => 30],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'monitor/system/interface',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateIpv4',      'display_order' => 40],
            ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'monitor/system/interface', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigatePortsStatistics', 'display_order' => 45],
            // Hardware sensors (temperature, fan, voltage, power)
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/sensor-info',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateSensorInfo',    'display_order' => 50],
            // VPN monitoring
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ipsec',           'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateVpnIpsec',     'display_order' => 60],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ssl',             'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateVpnSsl',       'display_order' => 70],
            // DHCP and licensing
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/dhcp',         'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateDhcp',         'display_order' => 80],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/license/status',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortgateLicense',       'display_order' => 90],
            // VLANs and Routes
            ['capability' => 'vlans',     'method' => 'GET', 'path' => 'cmdb/system/interface',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateVlans',        'display_order' => 95],
            ['capability' => 'routes',    'method' => 'GET', 'path' => 'monitor/router/ipv4',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeFortigateRoutes',       'display_order' => 100],
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
            'modules' => json_encode(['sensors','inventory','ports','device_info']),
            'capabilities' => json_encode(['sensors','inventory','ports','device_info']),
            'description' => 'Junos RPC/REST endpoints for system, chassis, interface info',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $junosTpl['key']], $junosTpl);
        $junosTplId = DB::table('device_api_templates')->where('key', 'juniper_junos')->value('id');
        $junosEndpoints = [
            ['capability' => 'device_info', 'method' => 'POST', 'path' => 'get-system-information',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosDeviceInfo', 'display_order' => 5],
            ['capability' => 'ports',     'method' => 'POST', 'path' => 'get-interface-information', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosInterfaces', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'get-chassis-inventory',     'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosInventory',  'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'POST', 'path' => 'get-system-information',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeJunosSystem',     'display_order' => 30],
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
            'modules' => json_encode(['inventory','ports','sensors','device_info']),
            'capabilities' => json_encode(['inventory','ports','sensors','device_info']),
            'description' => 'Dell OS10 REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $dellTpl['key']], $dellTpl);
        $dellTplId = DB::table('device_api_templates')->where('key', 'dell_os10')->value('id');
        $dellEndpoints = [
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'system',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellDeviceInfo', 'display_order' => 5],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellSystem',     'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',  'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeDellSensors',   'display_order' => 30],
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
            'modules' => json_encode(['inventory','ports','sensors','device_info']),
            'capabilities' => json_encode(['inventory','ports','sensors','device_info']),
            'description' => 'HPE Comware / Aruba REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $hpeTpl['key']], $hpeTpl);
        $hpeTplId = DB::table('device_api_templates')->where('key', 'hpe_network')->value('id');
        $hpeEndpoints = [
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'system',      'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeHpeDeviceInfo', 'display_order' => 5],
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
            'modules' => json_encode(['inventory','sensors','ports','device_info']),
            'capabilities' => json_encode(['inventory','sensors','ports','device_info']),
            'description' => 'HPE NimbleOS REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $nimbleTpl['key']], $nimbleTpl);
        $nimbleTplId = DB::table('device_api_templates')->where('key', 'hpe_nimble')->value('id');
        $nimbleEndpoints = [
            ['capability' => 'device_info', 'method' => 'GET', 'path' => 'arrays',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleDeviceInfo', 'display_order' => 5],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'arrays',        'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleArrays',     'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'disks',         'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleDisks',       'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/stats', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNimbleStats',       'display_order' => 30],
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
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'clusters/list',    'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNutanixClusters', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json'])],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'hosts/list',       'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNutanixHosts',    'display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json'])],
        ];
        foreach ($nutanixEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nutanixTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nutanixTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now, 'body' => json_encode(['kind' => 'cluster'])])
            );
        }

        // =========================================================================
        // VMware ESXi Host Template (DISABLED - vCenter endpoints don't work on ESXi)
        // =========================================================================
        // NOTE: Standalone ESXi hosts have extremely limited REST API support.
        // The /rest endpoints are primarily designed for vCenter Server, not ESXi hosts.
        // ESXi hosts use the SOAP-based vSphere API, not REST.
        // The vmware_esxi template (ID 12) was created with vCenter-specific endpoints:
        //   - appliance/system/version
        //   - appliance/health/system
        //   - vcenter/network
        // These endpoints return 404 on standalone ESXi hosts.
        //
        // RECOMMENDATION: Monitor ESXi hosts through vCenter instead of direct REST API.
        // If direct ESXi monitoring is required, use SNMP or the vSphere SOAP API.
        // The template is DISABLED to prevent discovery errors.
        // =========================================================================

        // =========================================================================
        // VMware ESXi Host SOAP Template (vSphere Web Services API)
        // =========================================================================
        $esxiSoapTemplate = [
            'key' => 'esxi_soap',
            'label' => 'VMware ESXi Host (SOAP API)',
            'os_keys' => json_encode(['vmware-esxi']),
            'schema_id' => $schemaId('esxi_soap'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/sdk',
                'username' => 'root',
            ]),
            'modules' => json_encode(['device_info', 'inventory', 'ports', 'ports_statistics', 'sensors', 'processors', 'mempools', 'storage', 'ipv4', 'vlans', 'vminfo']),
            'capabilities' => json_encode(['device_info', 'inventory', 'ports', 'ports_statistics', 'sensors', 'processors', 'mempools', 'storage', 'ipv4', 'vlans', 'vminfo']),
            'description' => 'VMware ESXi standalone host monitoring using vSphere SOAP API. Provides hardware, network, performance, storage, VLANs, IP information, and virtual machine discovery via native SOAP API.',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $esxiSoapTemplate['key']],
            $esxiSoapTemplate
        );

        $esxiSoapTemplateId = DB::table('device_api_templates')->where('key', 'esxi_soap')->value('id');

        // ESXi SOAP Endpoints
        // Note: 'method' => 'SOAP' tells DeviceApiExecutor to use EsxiSoapClient instead of HTTP client
        // The 'path' field contains the SOAP method name to call on EsxiSoapClient
        $esxiSoapEndpoints = [
            ['capability' => 'device_info', 'method' => 'SOAP', 'path' => 'fetchHostHardware', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeHardware', 'display_order' => 10, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'SOAP', 'path' => 'fetchHostHardware', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeInventory', 'display_order' => 20, 'enabled' => 1],
            ['capability' => 'ports', 'method' => 'SOAP', 'path' => 'fetchNetworkInterfaces', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeNetworkInterfaces', 'display_order' => 30, 'enabled' => 1],
            ['capability' => 'ipv4', 'method' => 'SOAP', 'path' => 'fetchIpv4Addresses', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeIpv4Addresses', 'display_order' => 33, 'enabled' => 1],
            ['capability' => 'ports_statistics', 'method' => 'SOAP', 'path' => 'fetchNetworkStatistics', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeNetworkStatistics', 'display_order' => 35, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'SOAP', 'path' => 'fetchHostPerformance', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizePerformance', 'display_order' => 40, 'enabled' => 1],
            ['capability' => 'processors', 'method' => 'SOAP', 'path' => 'fetchHostPerformance', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeProcessors', 'display_order' => 50, 'enabled' => 1],
            ['capability' => 'mempools', 'method' => 'SOAP', 'path' => 'fetchHostPerformance', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeMempools', 'display_order' => 60, 'enabled' => 1],
            ['capability' => 'storage', 'method' => 'SOAP', 'path' => 'fetchDatastores', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeDatastores', 'display_order' => 70, 'enabled' => 1],
            ['capability' => 'vlans', 'method' => 'SOAP', 'path' => 'fetchVlans', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeVlans', 'display_order' => 75, 'enabled' => 1],
            ['capability' => 'vminfo', 'method' => 'SOAP', 'path' => 'fetchVms', 'transform' => '\LibreNMS\Util\Normalizers\EsxiSoapNormalizer::normalizeVms', 'display_order' => 80, 'enabled' => 1],
        ];

        foreach ($esxiSoapEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $esxiSoapTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $esxiSoapTemplateId, 'created_at' => $now, 'updated_at' => $now])
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
            'modules' => json_encode(['inventory', 'ports', 'ipv4', 'storage', 'sensors', 'mempools', 'processors', 'ports_statistics', 'device_info']),
            'capabilities' => json_encode(['inventory', 'ports', 'ipv4', 'storage', 'sensors', 'mempools', 'processors', 'ports_statistics', 'device_info']),
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
                'path' => '/cluster',
                'method' => 'GET',
                'capability' => 'device_info',
                'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeNetappDeviceInfo',
                'display_order' => 5,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/cluster/nodes?fields=uuid,name,model,serial_number,version',
                'method' => 'GET',
                'capability' => 'inventory',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeClusterNodes',
                'display_order' => 10,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ethernet/ports?fields=uuid,name,node.name,speed,state,enabled,type,mtu,mac_address,broadcast_domain.name',
                'method' => 'GET',
                'capability' => 'ports',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeNetworkPorts',
                'display_order' => 20,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ip/interfaces',
                'method' => 'GET',
                'capability' => 'ipv4',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeIpv4',
                'display_order' => 30,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/storage/volumes?fields=uuid,name,size,space',
                'method' => 'GET',
                'capability' => 'storage',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeVolumes',
                'display_order' => 40,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/storage/volumes?fields=uuid,name,statistics',
                'method' => 'GET',
                'capability' => 'sensors',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeVolumePerformance',
                'display_order' => 45,
                'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/cluster/nodes?fields=name,statistics',
                'method' => 'GET',
                'capability' => 'processors',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeClusterProcessors',
                'display_order' => 55,
                'enabled' => 0, // Disabled: statistics field not available in ONTAP 9.8, requires 9.11+
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/cluster/nodes?fields=name,statistics',
                'method' => 'GET',
                'capability' => 'mempools',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizeClusterMempools',
                'display_order' => 56,
                'enabled' => 0, // Disabled: statistics field not available in ONTAP 9.8, requires 9.11+
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'template_id' => $templateId,
                'path' => '/network/ethernet/ports/{uuid}/metrics',
                'method' => 'GET',
                'capability' => 'ports_statistics',
                'transform' => 'LibreNMS\\Util\\Normalizers\\NetAppNormalizer::normalizePortMetrics',
                'display_order' => 60,
                'enabled' => 0,
                'for_each' => 'ports',
                'for_each_options' => json_encode(['placeholder' => 'uuid', 'value_key' => 'uuid']),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($endpoints as $endpoint) {
            // Use exact path matching to avoid conflicts between similar paths
            $existing = DB::table('device_api_template_endpoints')
                ->where('template_id', $endpoint['template_id'])
                ->where('path', $endpoint['path'])
                ->where('capability', $endpoint['capability'])
                ->first();

            if ($existing) {
                DB::table('device_api_template_endpoints')
                    ->where('id', $existing->id)
                    ->update($endpoint);
            } else {
                DB::table('device_api_template_endpoints')->insert($endpoint);
            }
        }

        // =========================================================================
        // VMware VeloCloud Orchestrator Template
        // =========================================================================
        $velocloudTemplate = [
            'key' => 'vmware_velocloud',
            'label' => 'VMware VeloCloud (SD-WAN)',
            'os_keys' => json_encode(['velocloud']),
            'schema_id' => $schemaId('vmware_velocloud_token'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}',
            ]),
            'modules' => json_encode(['device_info', 'inventory', 'ports', 'ipv4', 'sensors', 'mempools', 'processors', 'vlans']),
            'capabilities' => json_encode(['device_info', 'inventory', 'ports', 'ipv4', 'sensors', 'mempools', 'processors', 'vlans']),
            'description' => 'VMware VeloCloud SD-WAN Orchestrator API for monitoring edges, links, and network metrics using API token authentication.',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $velocloudTemplate['key']],
            $velocloudTemplate
        );

        $velocloudTemplateId = DB::table('device_api_templates')->where('key', 'vmware_velocloud')->value('id');

        // VeloCloud Orchestrator API Endpoints
        // Note: Paths should NOT include 'portal/rest' - the client adds it automatically
        $velocloudEndpoints = [
            // Device information
            ['capability' => 'device_info', 'method' => 'POST', 'path' => 'enterprise/getEnterpriseEdges', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudDeviceInfo', 'display_order' => 5, 'enabled' => 1],

            // Inventory (edge devices)
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'enterprise/getEnterpriseEdges', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudInventory', 'display_order' => 10, 'enabled' => 1],

            // Network interfaces/ports - OLD ENDPOINT (only returns 2 WAN interfaces)
            // Disabled in favor of getEdgeConfigurationStack which returns ALL interfaces
            ['capability' => 'ports', 'method' => 'POST', 'path' => 'monitoring/getAggregateEdgeLinkMetrics', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudPorts', 'display_order' => 20, 'enabled' => 0],

            // Network interfaces/ports - NEW ENDPOINT (returns ALL 8+ interfaces including WAN and LAN)
            ['capability' => 'ports', 'method' => 'POST', 'path' => 'edge/getEdgeConfigurationStack', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudConfigStackPorts', 'display_order' => 21, 'enabled' => 1],

            // Port labels - Disabled because it causes ports to be marked as deleted
            // Labels are now merged from the old normalizeVelocloudPorts endpoint
            // ['capability' => 'ports', 'method' => 'POST', 'path' => 'monitoring/getAggregateEdgeLinkMetrics', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudPortLabels', 'display_order' => 22, 'enabled' => 0],

            // IPv4 addresses - OLD ENDPOINT
            ['capability' => 'ipv4', 'method' => 'POST', 'path' => 'enterprise/getEnterpriseEdges', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudIpv4', 'display_order' => 30, 'enabled' => 0],

            // IPv4 addresses - NEW ENDPOINT (from configuration stack)
            ['capability' => 'ipv4', 'method' => 'POST', 'path' => 'edge/getEdgeConfigurationStack', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudConfigStackIpv4', 'display_order' => 31, 'enabled' => 1],

            // Sensors (link quality, tunnel status, etc.)
            ['capability' => 'sensors', 'method' => 'POST', 'path' => 'monitoring/getAggregateEdgeLinkMetrics', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudSensors', 'display_order' => 40, 'enabled' => 1],

            // CPU and memory
            ['capability' => 'processors', 'method' => 'POST', 'path' => 'monitoring/getAggregateEdgeLinkMetrics', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudProcessors', 'display_order' => 50, 'enabled' => 1],
            ['capability' => 'mempools', 'method' => 'POST', 'path' => 'monitoring/getAggregateEdgeLinkMetrics', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudMempools', 'display_order' => 60, 'enabled' => 1],

            // VLANs
            ['capability' => 'vlans', 'method' => 'POST', 'path' => 'enterprise/getEnterpriseEdges', 'transform' => '\LibreNMS\Modules\Support\RestNormalizers::normalizeVelocloudVlans', 'display_order' => 70, 'enabled' => 1],
        ];

        foreach ($velocloudEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $velocloudTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $velocloudTemplateId, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // =========================================================================
        // Cisco UCS Manager XML API Template
        // =========================================================================
        $ucsmXmlTemplate = [
            'key' => 'cisco_ucsm_xml',
            'label' => 'Cisco UCS Manager (XML API)',
            'os_keys' => json_encode(['cisco-usm']),
            'schema_id' => $schemaId('cisco_ucsm_xml'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}',
                'username' => 'admin',
                'session_timeout' => 600,
            ]),
            'modules' => json_encode(['inventory', 'sensors', 'processors', 'mempools', 'device_info']),
            'capabilities' => json_encode(['inventory', 'sensors', 'processors', 'mempools', 'device_info']),
            'description' => 'Cisco UCS Manager XML API for monitoring chassis, blades, fabric interconnects, and compute resources.',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $ucsmXmlTemplate['key']],
            $ucsmXmlTemplate
        );

        $ucsmTemplateId = DB::table('device_api_templates')->where('key', 'cisco_ucsm_xml')->value('id');

        // UCSM XML API Endpoints
        // Note: 'method' => 'XML' tells DeviceApiExecutor to use UcsmXmlClient
        // The 'path' field contains the client method name to call
        $ucsmXmlEndpoints = [
            // Device info and inventory
            ['capability' => 'device_info', 'method' => 'XML', 'path' => 'fetchTopSystem', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeTopSystem', 'display_order' => 1, 'enabled' => 1],
            ['capability' => 'device_info', 'method' => 'XML', 'path' => 'fetchFabricInterconnects', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeFabricInterconnects', 'display_order' => 5, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'XML', 'path' => 'fetchChassis', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeChassis', 'display_order' => 10, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'XML', 'path' => 'fetchBlades', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeBlades', 'display_order' => 20, 'enabled' => 1],
            ['capability' => 'inventory', 'method' => 'XML', 'path' => 'fetchFabricInterconnects', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeFabricInterconnects', 'display_order' => 30, 'enabled' => 1],

            // Sensors (state, faults, environmental)
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchTopSystem', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeTopSystem', 'display_order' => 35, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchPowerSupplies', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizePowerSupplies', 'display_order' => 40, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchFans', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeFans', 'display_order' => 50, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchFaults', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeFaults', 'display_order' => 60, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchAdapterVnicStats', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeAdapterVnicStats', 'display_order' => 62, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchEthernetErrorStats', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeEthernetErrorStats', 'display_order' => 64, 'enabled' => 1],
            ['capability' => 'sensors', 'method' => 'XML', 'path' => 'fetchTemperatureStats', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeChassisStats', 'display_order' => 66, 'enabled' => 1],

            // Processors and mempools come from blade normalization and FI stats
            ['capability' => 'processors', 'method' => 'XML', 'path' => 'fetchBlades', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeBlades', 'display_order' => 70, 'enabled' => 1],
            ['capability' => 'mempools', 'method' => 'XML', 'path' => 'fetchBlades', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeBlades', 'display_order' => 80, 'enabled' => 1],

            // Fabric Interconnect CPU and memory stats (for UCS Manager device metrics)
            ['capability' => 'processors', 'method' => 'XML', 'path' => 'fetchSwitchStats', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeSwitchStats', 'display_order' => 75, 'enabled' => 1],
            ['capability' => 'mempools', 'method' => 'XML', 'path' => 'fetchSwitchStats', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeSwitchStats', 'display_order' => 85, 'enabled' => 1],

            // Network ports (Ethernet and Fibre Channel physical interfaces)
            ['capability' => 'ports', 'method' => 'XML', 'path' => 'fetchFabricEthernetPorts', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeEthernetPhysicalPorts', 'display_order' => 90, 'enabled' => 1],
            ['capability' => 'ports', 'method' => 'XML', 'path' => 'fetchFibreChannelPorts', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeFibreChannelPorts', 'display_order' => 91, 'enabled' => 1],

            // Port statistics (traffic counters, errors)
            ['capability' => 'ports_stats', 'method' => 'XML', 'path' => 'fetchPortsStatistics', 'transform' => '\LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeEthernetTrafficStats', 'display_order' => 95, 'enabled' => 1],
        ];

        foreach ($ucsmXmlEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $ucsmTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $ucsmTemplateId, 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}