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
            'schema_id' => $schemaId('custom_token_login'),
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
            'schema_id' => $schemaId('token'), // default to token auth; users can switch to ticket
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
    }
}