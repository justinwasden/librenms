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
            'key' => 'pure_flasharray_default',
            'label' => 'Pure Storage FlashArray (Default)',
            'os_keys' => json_encode(['purestorage.flasharray']),
            'schema_id' => $schemaId('purestorage_api_token_login'),
            'default_values' => json_encode([
                'api_login_url' => 'https://{hostname}/api/2.26/login',
                'api_login_header_key' => 'api-token',
                'api_session_header_key' => 'X-Auth-Token',
                'api_session_expiry_minutes' => 30,
            ]),
            'modules' => json_encode(['sensors','inventory','ports','ipv4']),
            'capabilities' => json_encode(['sensors','inventory','ports','ipv4']),
            'description' => 'Login via API token to obtain session header and poll common array endpoints.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $pureTemplate['key']],
            $pureTemplate
        );
        $pureTemplateId = DB::table('device_api_templates')->where('key', 'pure_flasharray_default')->value('id');

        $pureEndpoints = [
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'array',              'transform' => 'normalizePureArraySensors', 'display_order' => 10],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'array/performance',  'transform' => 'normalizePureArraySensors', 'display_order' => 20],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hardware',           'transform' => 'normalizePureHardware',     'display_order' => 30],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'drives',             'transform' => 'normalizePureHardware',     'display_order' => 40],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'hosts',              'transform' => 'normalizePureHosts',        'display_order' => 50],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network-interfaces', 'transform' => 'normalizePureNetworkInterfaces', 'display_order' => 60],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'network-interfaces', 'transform' => 'normalizePureIpv4',         'display_order' => 70],
        ];
        foreach ($pureEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $pureTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $pureTemplateId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Proxmox VE Node template
        $pxTemplate = [
            'key' => 'proxmox_node_default',
            'label' => 'Proxmox VE Node (Default)',
            'os_keys' => json_encode(['proxmox.ve.node']),
            'schema_id' => $schemaId('proxmox_token'), // default to token auth; users can switch to ticket
            'default_values' => json_encode([
                'api_token_user' => 'user@pve',
                'api_token_id'   => 'tokenid',
            ]),
            'modules' => json_encode(['sensors','mempools','processors','ports','inventory']),
            'capabilities' => json_encode(['sensors','mempools','processors','ports','inventory','ipv4']),
            'description' => 'Proxmox node endpoints for status, network, storage.',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(
            ['key' => $pxTemplate['key']],
            $pxTemplate
        );
        $pxTemplateId = DB::table('device_api_templates')->where('key', 'proxmox_node_default')->value('id');

        $pxEndpoints = [
            ['capability' => 'sensors',    'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'normalizeProxmoxNodeStatus',    'display_order' => 10],
            ['capability' => 'mempools',   'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'normalizeProxmoxNodeStatus',    'display_order' => 20],
            ['capability' => 'processors', 'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'normalizeProxmoxNodeStatus',    'display_order' => 30],
            ['capability' => 'ports',      'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => 'normalizeProxmoxNodeNetwork',   'display_order' => 40],
            ['capability' => 'inventory',  'method' => 'GET', 'path' => 'nodes/{node}/storage', 'transform' => 'normalizeProxmoxNodeStorage',   'display_order' => 50],
            ['capability' => 'ipv4',       'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => 'normalizeProxmoxIpv4',          'display_order' => 60],
        ];
        foreach ($pxEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $pxTemplateId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $pxTemplateId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // =========================
        // Additional Vendors
        // =========================

        // Fortinet FortiGate
        $fortiTpl = [
            'key' => 'fortinet_fortigate',
            'label' => 'Fortinet FortiGate',
            'os_keys' => json_encode(['fortinet.fortigate']),
            'schema_id' => $schemaId('bearer'), // FortiGate often uses API token -> Bearer; adjust if you prefer custom header schema
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
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/resource/usage', 'transform' => 'normalizeFortigateSystemUsage', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'monitor/system/status',         'transform' => 'normalizeFortigateSystemStatus','display_order' => 20],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'cmdb/system/interface',         'transform' => 'normalizeFortigateInterfaces', 'display_order' => 30],
            ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'cmdb/system/interface',         'transform' => 'normalizeFortigateIpv4',       'display_order' => 40],
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
            'os_keys' => json_encode(['juniper.junos']),
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
            ['capability' => 'ports',     'method' => 'POST', 'path' => 'get-interface-information', 'transform' => 'normalizeJunosInterfaces', 'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'get-chassis-inventory',     'transform' => 'normalizeJunosInventory',  'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'POST', 'path' => 'get-system-information',     'transform' => 'normalizeJunosSystem',     'display_order' => 30],
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
            'label' => 'Dell OS10 / Force10',
            'os_keys' => json_encode(['dell.os10', 'force10']),
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
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',       'transform' => 'normalizeDellSystem',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',   'transform' => 'normalizeDellInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment',  'transform' => 'normalizeDellSensors',   'display_order' => 30],
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
            'os_keys' => json_encode(['hpe.comware', 'aruba.cx']),
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
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',      'transform' => 'normalizeHpeSystem',     'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',  'transform' => 'normalizeHpeInterfaces', 'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment', 'transform' => 'normalizeHpeSensors',    'display_order' => 30],
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
            'os_keys' => json_encode(['hpe.nimble']),
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
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'arrays',        'transform' => 'normalizeNimbleArrays',     'display_order' => 10],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'disks',         'transform' => 'normalizeNimbleDisks',      'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/stats', 'transform' => 'normalizeNimbleStats',      'display_order' => 30],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network',       'transform' => 'normalizeNimbleInterfaces', 'display_order' => 40],
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
            'os_keys' => json_encode(['nutanix.prism']),
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
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'clusters/list',     'transform' => 'normalizeNutanixClusters', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json'])],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'hosts/list',        'transform' => 'normalizeNutanixHosts',    'display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json'])],
            ['capability' => 'sensors',   'method' => 'POST', 'path' => 'storage_containers/list', 'transform' => 'normalizeNutanixStorage','display_order' => 30, 'headers' => json_encode(['Content-Type' => 'application/json'])],
        ];
        foreach ($nutanixEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nutanixTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nutanixTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Cisco ISE (ERS)
        $iseTpl = [
            'key' => 'cisco_ise',
            'label' => 'Cisco ISE (ERS)',
            'os_keys' => json_encode(['cisco.ise']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}:9060/ers',
            ]),
            'modules' => json_encode(['inventory','ports']),
            'capabilities' => json_encode(['inventory','ports']),
            'description' => 'Cisco ISE ERS API (network devices; endpoints)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $iseTpl['key']], $iseTpl);
        $iseTplId = DB::table('device_api_templates')->where('key', 'cisco_ise')->value('id');
        $iseEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'config/networkdevice', 'transform' => 'normalizeIseNetworkDevices', 'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'config/endpoint',      'transform' => 'normalizeIseEndpoints',      'display_order' => 20],
        ];
        foreach ($iseEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $iseTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $iseTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // VMware vCenter
        $vcenterTpl = [
            'key' => 'vmware_vcenter_default',
            'label' => 'VMware vCenter (Default)',
            'os_keys' => json_encode(['vmware.vcenter']),
            'schema_id' => $schemaId('vmware_vcenter_session'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api',
            ]),
            'modules' => json_encode(['inventory','ports','sensors','processors','mempools']),
            'capabilities' => json_encode(['inventory','ports','sensors','processors','mempools']),
            'description' => 'VMware vCenter REST API (inventory; metrics)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $vcenterTpl['key']], $vcenterTpl);
        $vcenterTplId = DB::table('device_api_templates')->where('key', 'vmware_vcenter_default')->value('id');
        $vcenterEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'vcenter/host',     'transform' => 'vcHostsToInventory',                'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'vcenter/network',  'transform' => 'vcNetworksToPortsInventory',        'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'vcenter/datastore','transform' => 'vcDatastoresToStorageSensors',      'display_order' => 30],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'vcenter/cluster',  'transform' => 'vcClustersToInventory',             'display_order' => 40],
            ['capability' => 'processors','method' => 'GET', 'path' => 'vcenter/host',     'transform' => 'vcHostSummaryToProcessorsMempools', 'display_order' => 50],
            ['capability' => 'mempools',  'method' => 'GET', 'path' => 'vcenter/host',     'transform' => 'vcHostSummaryToProcessorsMempools', 'display_order' => 60],
        ];
        foreach ($vcenterEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $vcenterTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $vcenterTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // VMware ESXi (host)
        $esxiTpl = [
            'key' => 'vmware_esxi',
            'label' => 'VMware ESXi Host',
            'os_keys' => json_encode(['vmware.esxi']),
            'schema_id' => $schemaId('basic'), // ESXi host REST often uses basic auth
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rest',
            ]),
            'modules' => json_encode(['inventory','sensors','ports']),
            'capabilities' => json_encode(['inventory','sensors','ports']),
            'description' => 'VMware ESXi host REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $esxiTpl['key']], $esxiTpl);
        $esxiTplId = DB::table('device_api_templates')->where('key', 'vmware_esxi')->value('id');
        $esxiEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'appliance/system/version', 'transform' => 'normalizeEsxiVersion',     'display_order' => 10],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'appliance/health/system',  'transform' => 'normalizeEsxiHealth',      'display_order' => 20],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'vcenter/network',          'transform' => 'vcNetworksToPortsInventory','display_order' => 30],
        ];
        foreach ($esxiEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $esxiTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $esxiTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Palo Alto Networks PAN-OS
        $panTpl = [
            'key' => 'paloalto_panos',
            'label' => 'Palo Alto PAN-OS',
            'os_keys' => json_encode(['paloalto.panos']),
            'schema_id' => $schemaId('apikey'), // PAN-OS uses API key; if custom header needed use apikey_custom_header
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/restapi',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Palo Alto PAN-OS REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $panTpl['key']], $panTpl);
        $panTplId = DB::table('device_api_templates')->where('key', 'paloalto_panos')->value('id');
        $panEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'v10.0/telemetry',     'transform' => 'normalizePanInventory', 'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'v10.0/network/interface', 'transform' => 'normalizePanInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'v10.0/system/info',   'transform' => 'normalizePanSystem',    'display_order' => 30],
        ];
        foreach ($panEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $panTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $panTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Cisco NX-OS (NX-API)
        $nxTpl = [
            'key' => 'cisco_nxos',
            'label' => 'Cisco NX-OS (NX-API)',
            'os_keys' => json_encode(['cisco.nxos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/ins',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Cisco NX-API (Nexus) JSON-RPC',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $nxTpl['key']], $nxTpl);
        $nxTplId = DB::table('device_api_templates')->where('key', 'cisco_nxos')->value('id');
        $nxEndpoints = [
            ['capability' => 'ports',     'method' => 'POST', 'path' => '', 'transform' => 'normalizeNxInterfaces', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json']), 'request_body' => json_encode(['ins_api' => ['version' => '1.0', 'type' => 'cli', 'chunk' => '0', 'sid' => '1', 'input' => 'show interface', 'output_format' => 'json']])],
            ['capability' => 'inventory', 'method' => 'POST', 'path' => '', 'transform' => 'normalizeNxInventory',  'display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json']), 'request_body' => json_encode(['ins_api' => ['version' => '1.0', 'type' => 'cli', 'chunk' => '0', 'sid' => '1', 'input' => 'show inventory', 'output_format' => 'json']])],
        ];
        foreach ($nxEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nxTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nxTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Cisco IOS-XR (RESTCONF)
        $iosxrTpl = [
            'key' => 'cisco_ios_xr',
            'label' => 'Cisco IOS-XR (RESTCONF)',
            'os_keys' => json_encode(['cisco.iosxr']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/restconf',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Cisco IOS-XR RESTCONF API (ietf-interfaces; native data models)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $iosxrTpl['key']], $iosxrTpl);
        $iosxrTplId = DB::table('device_api_templates')->where('key', 'cisco_ios_xr')->value('id');
        $iosxrEndpoints = [
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'data/ietf-interfaces:interfaces', 'transform' => 'normalizeIosxrInterfaces', 'display_order' => 10, 'headers' => json_encode(['Accept' => 'application/yang-data+json'])],
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'data',                              'transform' => 'normalizeIosxrInventory',  'display_order' => 20, 'headers' => json_encode(['Accept' => 'application/yang-data+json'])],
        ];
        foreach ($iosxrEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $iosxrTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $iosxrTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Cisco CUCM (AXL SOAP)
        $cucmTpl = [
            'key' => 'cisco_cucm',
            'label' => 'Cisco CUCM (AXL)',
            'os_keys' => json_encode(['cisco.cucm']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}:8443/axl',
            ]),
            'modules' => json_encode(['inventory']),
            'capabilities' => json_encode(['inventory']),
            'description' => 'Cisco CUCM AXL SOAP API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $cucmTpl['key']], $cucmTpl);
        $cucmTplId = DB::table('device_api_templates')->where('key', 'cisco_cucm')->value('id');
        $cucmEndpoints = [
            ['capability' => 'inventory', 'method' => 'POST', 'path' => '', 'transform' => 'normalizeCucmInventory', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'text/xml']), 'request_body' => json_encode(['axl' => '<soapenv:Envelope>...</soapenv:Envelope>'])],
        ];
        foreach ($cucmEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $cucmTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $cucmTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Calix (Generic REST)
        $calixTpl = [
            'key' => 'calix_generic',
            'label' => 'Calix Systems',
            'os_keys' => json_encode(['calix']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/api',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Calix generic REST API endpoints',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $calixTpl['key']], $calixTpl);
        $calixTplId = DB::table('device_api_templates')->where('key', 'calix_generic')->value('id');
        $calixEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'devices',      'transform' => 'normalizeCalixDevices',   'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces',   'transform' => 'normalizeCalixInterfaces', 'display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment',  'transform' => 'normalizeCalixSensors',    'display_order' => 30],
        ];
        foreach ($calixEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $calixTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $calixTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Cisco NDFC/DCNM
        $nceTpl = [
            'key' => 'cisco_ndfc',
            'label' => 'Cisco NDFC/DCNM',
            'os_keys' => json_encode(['cisco.ndfc']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/appcentre/ndfc',
            ]),
            'modules' => json_encode(['inventory','ports']),
            'capabilities' => json_encode(['inventory','ports']),
            'description' => 'Cisco NDFC/DCNM REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $nceTpl['key']], $nceTpl);
        $nceTplId = DB::table('device_api_templates')->where('key', 'cisco_ndfc')->value('id');
        $nceEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'api/ndfc/v1/inventory/devices', 'transform' => 'normalizeNdfcDevices',   'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'api/ndfc/v1/inventory/interfaces', 'transform' => 'normalizeNdfcInterfaces','display_order' => 20],
        ];
        foreach ($nceEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $nceTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $nceTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Arista EOS (eAPI)
        $aristaTpl = [
            'key' => 'arista_eos',
            'label' => 'Arista EOS (eAPI)',
            'os_keys' => json_encode(['arista.eos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/command-api',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Arista EOS eAPI JSON',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $aristaTpl['key']], $aristaTpl);
        $aristaTplId = DB::table('device_api_templates')->where('key', 'arista_eos')->value('id');
        $aristaEndpoints = [
            ['capability' => 'inventory', 'method' => 'POST', 'path' => '', 'transform' => 'normalizeAristaSystem',    'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json']), 'request_body' => json_encode(['cmds' => ['show version'], 'version' => 1])],
            ['capability' => 'ports',     'method' => 'POST', 'path' => '', 'transform' => 'normalizeAristaInterfaces','display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json']), 'request_body' => json_encode(['cmds' => ['show interfaces'], 'version' => 1])],
            ['capability' => 'sensors',   'method' => 'POST', 'path' => '', 'transform' => 'normalizeAristaSensors',   'display_order' => 30, 'headers' => json_encode(['Content-Type' => 'application/json']), 'request_body' => json_encode(['cmds' => ['show environment'], 'version' => 1])],
        ];
        foreach ($aristaEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $aristaTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $aristaTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Extreme Networks (XOS/EXOS REST) - Generic
        $extremeTpl = [
            'key' => 'extreme_exos',
            'label' => 'Extreme Networks EXOS',
            'os_keys' => json_encode(['extreme.exos']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rest',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Extreme EXOS REST API (generic)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $extremeTpl['key']], $extremeTpl);
        $extremeTplId = DB::table('device_api_templates')->where('key', 'extreme_exos')->value('id');
        $extremeEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',     'transform' => 'normalizeExtremeSystem',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces', 'transform' => 'normalizeExtremeInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'environment','transform' => 'normalizeExtremeSensors',   'display_order' => 30],
        ];
        foreach ($extremeEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $extremeTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $extremeTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Brocade/Foundry (FastIron/NetIron) - Generic REST placeholder
        $brocadeTpl = [
            'key' => 'brocade_fastiron',
            'label' => 'Brocade FastIron (Generic)',
            'os_keys' => json_encode(['brocade.fastiron']),
            'schema_id' => $schemaId('basic'),
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/rest',
            ]),
            'modules' => json_encode(['inventory','ports']),
            'capabilities' => json_encode(['inventory','ports']),
            'description' => 'Brocade FastIron/NetIron generic REST endpoints (if available)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $brocadeTpl['key']], $brocadeTpl);
        $brocadeTplId = DB::table('device_api_templates')->where('key', 'brocade_fastiron')->value('id');
        $brocadeEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system',     'transform' => 'normalizeBrocadeSystem',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'interfaces', 'transform' => 'normalizeBrocadeInterfaces','display_order' => 20],
        ];
        foreach ($brocadeEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $brocadeTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $brocadeTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // SonicWall (Gen7 REST)
        $sonicTpl = [
            'key' => 'sonicwall_gen7',
            'label' => 'SonicWall Gen7',
            'os_keys' => json_encode(['sonicwall.gen7']),
            'schema_id' => $schemaId('basic'), // SonicWall REST often uses basic or cookie auth; start with basic
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/sonicos',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'SonicWall Gen7 REST API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $sonicTpl['key']], $sonicTpl);
        $sonicTplId = DB::table('device_api_templates')->where('key', 'sonicwall_gen7')->value('id');
        $sonicEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'system/status', 'transform' => 'normalizeSonicSystem',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'network/interface', 'transform' => 'normalizeSonicInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'system/diag',   'transform' => 'normalizeSonicSensors',   'display_order' => 30],
        ];
        foreach ($sonicEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $sonicTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $sonicTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Check Point (Management API)
        $checkpointTpl = [
            'key' => 'checkpoint_mgmt',
            'label' => 'Check Point Management',
            'os_keys' => json_encode(['checkpoint.mgmt']),
            'schema_id' => $schemaId('checkpoint_session'), // custom session auth
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}:443/web_api',
            ]),
            'modules' => json_encode(['inventory','ports']),
            'capabilities' => json_encode(['inventory','ports']),
            'description' => 'Check Point R80+ Management API (inventory; network interfaces)',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $checkpointTpl['key']], $checkpointTpl);
        $checkpointTplId = DB::table('device_api_templates')->where('key', 'checkpoint_mgmt')->value('id');
        $checkpointEndpoints = [
            ['capability' => 'inventory', 'method' => 'POST', 'path' => 'show-gateways-and-servers', 'transform' => 'normalizeCheckpointGateways', 'display_order' => 10, 'headers' => json_encode(['Content-Type' => 'application/json'])],
            ['capability' => 'ports',     'method' => 'POST', 'path' => 'show-interfaces',            'transform' => 'normalizeCheckpointInterfaces','display_order' => 20, 'headers' => json_encode(['Content-Type' => 'application/json'])],
        ];
        foreach ($checkpointEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $checkpointTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $checkpointTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Aruba Central (Platform API)
        $arubaTpl = [
            'key' => 'aruba_central',
            'label' => 'Aruba Central',
            'os_keys' => json_encode(['aruba.central']),
            'schema_id' => $schemaId('oauth2_client_credentials'), // Central uses OAuth
            'default_values' => json_encode([
                'base_url_pattern' => 'https://{hostname}/platform',
            ]),
            'modules' => json_encode(['inventory','ports','sensors']),
            'capabilities' => json_encode(['inventory','ports','sensors']),
            'description' => 'Aruba Central Platform API',
            'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('device_api_templates')->updateOrInsert(['key' => $arubaTpl['key']], $arubaTpl);
        $arubaTplId = DB::table('device_api_templates')->where('key', 'aruba_central')->value('id');
        $arubaEndpoints = [
            ['capability' => 'inventory', 'method' => 'GET', 'path' => 'device_inventory/v1/devices', 'transform' => 'normalizeArubaDevices',    'display_order' => 10],
            ['capability' => 'ports',     'method' => 'GET', 'path' => 'device_management/v1/interfaces', 'transform' => 'normalizeArubaInterfaces','display_order' => 20],
            ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitoring/v1/devices', 'transform' => 'normalizeArubaMonitoring', 'display_order' => 30],
        ];
        foreach ($arubaEndpoints as $ep) {
            DB::table('device_api_template_endpoints')->updateOrInsert(
                ['template_id' => $arubaTplId, 'capability' => $ep['capability'], 'path' => $ep['path']],
                array_merge($ep, ['template_id' => $arubaTplId, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}