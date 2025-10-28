<?php

namespace Database\Seeders;

use App\Models\DeviceApiAuthSchema;
use App\Models\DeviceApiAuthSchemaField;
use App\Models\DeviceApiTemplate;
use App\Models\DeviceApiTemplateEndpoint;
use Illuminate\Database\Seeder;

class DeviceApiSeeder extends Seeder
{
    public function run(): void
    {
        // Create Auth Schemas
        $this->createAuthSchemas();

        // Create Templates
        $this->createTemplates();
    }

    private function createAuthSchemas(): void
    {
        // API Key / Bearer Token Schema
        $bearerSchema = DeviceApiAuthSchema::create([
            'key' => 'bearer',
            'label' => 'Bearer Token',
            'description' => 'Simple bearer token authentication',
            'vendor' => 'generic',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $bearerSchema->id,
            'name' => 'api_token',
            'label' => 'API Token',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your API token',
            'display_order' => 1,
        ]);

        // API Key Schema (custom header)
        $apikeySchema = DeviceApiAuthSchema::create([
            'key' => 'apikey',
            'label' => 'API Key',
            'description' => 'API Key in X-API-Key header',
            'vendor' => 'generic',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $apikeySchema->id,
            'name' => 'api_key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your API key',
            'display_order' => 1,
        ]);

        // Basic Auth Schema
        $basicSchema = DeviceApiAuthSchema::create([
            'key' => 'basic',
            'label' => 'Basic Authentication',
            'description' => 'Username and password authentication',
            'vendor' => 'generic',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $basicSchema->id,
            'name' => 'username',
            'label' => 'Username',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'Username',
            'display_order' => 1,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $basicSchema->id,
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Password',
            'display_order' => 2,
        ]);

        // PureStorage FlashArray Schema
        $pureStorageSchema = DeviceApiAuthSchema::create([
            'key' => 'purestorage_api_token',
            'label' => 'Pure Storage API Token',
            'description' => 'Pure Storage FlashArray API Token authentication',
            'vendor' => 'purestorage',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $pureStorageSchema->id,
            'name' => 'api_token',
            'label' => 'API Token',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your Pure Storage API token',
            'display_order' => 1,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $pureStorageSchema->id,
            'name' => 'login_path',
            'label' => 'Login Path',
            'type' => 'text',
            'required' => false,
            'encrypted' => false,
            'placeholder' => '/login',
            'default' => '/login',
            'display_order' => 2,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $pureStorageSchema->id,
            'name' => 'auth_header_name',
            'label' => 'Auth Header Name',
            'type' => 'text',
            'required' => false,
            'encrypted' => false,
            'placeholder' => 'X-Auth-Token',
            'default' => 'X-Auth-Token',
            'display_order' => 3,
        ]);

        // Proxmox Token Schema
        $proxmoxTokenSchema = DeviceApiAuthSchema::create([
            'key' => 'proxmox_token',
            'label' => 'Proxmox API Token',
            'description' => 'Proxmox VE API Token authentication',
            'vendor' => 'proxmox',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $proxmoxTokenSchema->id,
            'name' => 'token_user',
            'label' => 'Token User@Realm',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'user@pve',
            'display_order' => 1,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $proxmoxTokenSchema->id,
            'name' => 'token_id',
            'label' => 'Token ID',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'tokenid',
            'display_order' => 2,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $proxmoxTokenSchema->id,
            'name' => 'token_secret',
            'label' => 'Token Secret',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Secret value',
            'display_order' => 3,
        ]);

        // Proxmox Ticket Schema
        $proxmoxTicketSchema = DeviceApiAuthSchema::create([
            'key' => 'proxmox_ticket',
            'label' => 'Proxmox Username/Password',
            'description' => 'Proxmox VE username/password authentication',
            'vendor' => 'proxmox',
            'enabled' => true,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $proxmoxTicketSchema->id,
            'name' => 'username',
            'label' => 'Username@Realm',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'root@pam',
            'display_order' => 1,
        ]);

        DeviceApiAuthSchemaField::create([
            'schema_id' => $proxmoxTicketSchema->id,
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Password',
            'display_order' => 2,
        ]);
    }

    private function createTemplates(): void
    {
        // PureStorage FlashArray Template
        $pureSchema = DeviceApiAuthSchema::where('key', 'purestorage_api_token')->first();
        $pureTemplate = DeviceApiTemplate::create([
            'key' => 'purestorage_flasharray',
            'label' => 'Pure Storage FlashArray',
            'os_keys' => ['purestorage'],
            'schema_id' => $pureSchema->id,
            'default_values' => [
                'base_url_pattern' => 'https://{hostname}/api/2.26',
            ],
            'modules' => ['sensors', 'ports', 'inventory'],
            'capabilities' => ['sensors', 'ports', 'inventory'],
            'description' => 'Template for Pure Storage FlashArray REST API v2.x',
            'enabled' => true,
        ]);

        // PureStorage Endpoints
        $pureEndpoints = [
            ['capability' => 'sensors', 'path' => '/arrays', 'transform' => 'normalizePureArraySensors', 'order' => 1],
            ['capability' => 'sensors', 'path' => '/arrays/performance', 'transform' => 'normalizePureArraySensors', 'order' => 2],
            ['capability' => 'sensors', 'path' => '/hardware', 'transform' => 'normalizePureHardware', 'order' => 3],
            ['capability' => 'ports', 'path' => '/network-interfaces', 'transform' => 'normalizePureNetworkInterfaces', 'order' => 1],
            ['capability' => 'inventory', 'path' => '/hardware', 'transform' => 'normalizePureHardware', 'order' => 1],
            ['capability' => 'inventory', 'path' => '/hosts', 'transform' => 'normalizePureHosts', 'order' => 2],
        ];

        foreach ($pureEndpoints as $idx => $endpoint) {
            DeviceApiTemplateEndpoint::create([
                'template_id' => $pureTemplate->id,
                'capability' => $endpoint['capability'],
                'method' => 'GET',
                'path' => $endpoint['path'],
                'enabled' => true,
                'transform' => $endpoint['transform'],
                'display_order' => $endpoint['order'],
            ]);
        }

        // Proxmox Template (Token Auth)
        $proxmoxTokenSchema = DeviceApiAuthSchema::where('key', 'proxmox_token')->first();
        $proxmoxTemplate = DeviceApiTemplate::create([
            'key' => 'proxmox_ve_token',
            'label' => 'Proxmox VE (API Token)',
            'os_keys' => ['proxmox'],
            'schema_id' => $proxmoxTokenSchema->id,
            'default_values' => [
                'base_url_pattern' => 'https://{hostname}:8006/api2/json',
            ],
            'modules' => ['sensors', 'ports', 'processors', 'mempools'],
            'capabilities' => ['sensors', 'ports', 'processors', 'mempools'],
            'description' => 'Template for Proxmox VE with API Token auth',
            'enabled' => true,
        ]);

        // Proxmox Endpoints
        $proxmoxEndpoints = [
            // Cluster endpoints (no placeholders - good for testing)
            ['capability' => 'sensors', 'path' => '/cluster/resources', 'transform' => 'normalizeProxmoxClusterResources', 'order' => 1],
            ['capability' => 'sensors', 'path' => '/cluster/status', 'transform' => 'normalizeProxmoxClusterStatus', 'order' => 2],
            // Node-specific endpoints (require {node} placeholder)
            ['capability' => 'sensors', 'path' => '/nodes/{node}/status', 'transform' => 'normalizeProxmoxNodeStatus', 'order' => 3],
            ['capability' => 'sensors', 'path' => '/nodes/{node}/version', 'transform' => 'normalizeProxmoxNodeVersion', 'order' => 4],
            ['capability' => 'storage', 'path' => '/nodes/{node}/storage', 'transform' => 'normalizeProxmoxNodeStorage', 'order' => 5],
            ['capability' => 'ports', 'path' => '/nodes/{node}/network', 'transform' => 'normalizeProxmoxNodeNetwork', 'order' => 6],
            ['capability' => 'vm', 'path' => '/nodes/{node}/qemu', 'transform' => 'normalizeProxmoxQemu', 'order' => 7],
            ['capability' => 'vm', 'path' => '/nodes/{node}/lxc', 'transform' => 'normalizeProxmoxLxc', 'order' => 8],
        ];

        foreach ($proxmoxEndpoints as $idx => $endpoint) {
            DeviceApiTemplateEndpoint::create([
                'template_id' => $proxmoxTemplate->id,
                'capability' => $endpoint['capability'],
                'method' => 'GET',
                'path' => $endpoint['path'],
                'enabled' => true,
                'transform' => $endpoint['transform'],
                'display_order' => $endpoint['order'],
            ]);
        }

        // Generic Template
        $genericSchema = DeviceApiAuthSchema::where('key', 'bearer')->first();
        $genericTemplate = DeviceApiTemplate::create([
            'key' => 'generic_rest_api',
            'label' => 'Generic REST API',
            'os_keys' => [], // Empty means supports all OS
            'schema_id' => $genericSchema->id,
            'default_values' => [],
            'modules' => [],
            'capabilities' => [],
            'description' => 'Generic REST API template for custom integrations',
            'enabled' => true,
        ]);
    }
}
