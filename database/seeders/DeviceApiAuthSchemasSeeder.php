<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeviceApiAuthSchemasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Base schemas: bearer and custom_token_login (Pure), proxmox token/ticket
        $schemas = [
            ['key' => 'bearer', 'label' => 'Bearer Token', 'description' => 'Authorization: Bearer <token>', 'vendor' => 'generic', 'enabled' => 1],
            ['key' => 'custom_token_login', 'label' => 'Custom Token Login (Pure Storage)', 'description' => 'Login with API token to obtain session header', 'vendor' => 'purestorage', 'enabled' => 1],
            ['key' => 'token', 'label' => 'Proxmox API Token', 'description' => 'Authorization via PVEAPIToken', 'vendor' => 'proxmox', 'enabled' => 1],
            ['key' => 'ticket', 'label' => 'Proxmox Ticket', 'description' => 'Login to obtain session cookie', 'vendor' => 'proxmox', 'enabled' => 1],
        ];

        foreach ($schemas as $schema) {
            DB::table('device_api_auth_schemas')->updateOrInsert(
                ['key' => $schema['key']],
                array_merge($schema, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // Fields for each schema
        $schemaId = fn($key) => DB::table('device_api_auth_schemas')->where('key', $key)->value('id');

        $fields = [
            'bearer' => [
                ['name' => 'api_bearer_token', 'label' => 'Bearer Token', 'type' => 'password', 'required' => true, 'encrypted' => true, 'display_order' => 10],
            ],
            'custom_token_login' => [
                ['name' => 'api_login_url', 'label' => 'Login URL', 'type' => 'url', 'required' => true, 'encrypted' => false, 'default' => '', 'display_order' => 10],
                ['name' => 'api_login_header_key', 'label' => 'Login Header Key', 'type' => 'text', 'required' => true, 'encrypted' => false, 'default' => 'api-token', 'display_order' => 20],
                ['name' => 'api_login_header_value', 'label' => 'Login Header Value', 'type' => 'password', 'required' => true, 'encrypted' => true, 'display_order' => 30],
                ['name' => 'api_session_header_key', 'label' => 'Session Header Key', 'type' => 'text', 'required' => true, 'encrypted' => false, 'default' => 'X-Auth-Token', 'display_order' => 40],
                ['name' => 'api_session_expiry_minutes', 'label' => 'Session Expiry (minutes)', 'type' => 'number', 'required' => false, 'encrypted' => false, 'default' => '30', 'display_order' => 50],
            ],
            'token' => [
                ['name' => 'api_token_user', 'label' => 'Token User@Realm', 'type' => 'text', 'required' => true, 'encrypted' => false, 'display_order' => 10],
                ['name' => 'api_token_id', 'label' => 'Token ID', 'type' => 'text', 'required' => true, 'encrypted' => false, 'display_order' => 20],
                ['name' => 'api_token_secret', 'label' => 'Token Secret', 'type' => 'password', 'required' => true, 'encrypted' => true, 'display_order' => 30],
            ],
            'ticket' => [
                ['name' => 'api_username', 'label' => 'Username@Realm', 'type' => 'text', 'required' => true, 'encrypted' => false, 'display_order' => 10],
                ['name' => 'api_password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'encrypted' => true, 'display_order' => 20],
            ],
        ];

        foreach ($fields as $schemaKey => $schemaFields) {
            $sid = $schemaId($schemaKey);
            foreach ($schemaFields as $f) {
                DB::table('device_api_auth_schema_fields')->updateOrInsert(
                    ['schema_id' => $sid, 'name' => $f['name']],
                    array_merge($f, ['created_at' => $now, 'updated_at' => $now])
                );
            }
        }
    }
}