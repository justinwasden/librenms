<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestApiAuthenticationTypesSeeder extends Seeder
{
    public function run()
    {
        $types = [
            [
                'name' => 'Basic Auth',
                'description' => 'HTTP Basic authentication',
                'required_params' => json_encode(['username', 'password']),
            ],
            [
                'name' => 'API Key',
                'description' => 'Simple API key authentication',
                'required_params' => json_encode(['api_key', 'header_name']),
            ],
            [
                'name' => 'Bearer Token',
                'description' => 'Bearer token authentication',
                'required_params' => json_encode(['token']),
            ],
            [
                'name' => 'Session Token',
                'description' => 'Two-stage session token authentication',
                'required_params' => json_encode(['api_token', 'login_path', 'login_method', 'api_token_header', 'session_token_header', 'token_header']),
            ],
            [
                'name' => 'OAuth2',
                'description' => 'OAuth2 authentication',
                'required_params' => json_encode(['client_id', 'client_secret', 'token_url']),
            ],
            [
                'name' => 'ProxMox API Token',
                'description' => 'ProxMox User/Realm/ID/Secret',
                'required_params' => json_encode(['user_realm', 'token_id', 'token_secret']),
            ],
            [
                'name' => 'OAuth2',
                'description' => 'OAuth2 authentication',
                'required_params' => json_encode(['client_id', 'client_secret', 'token_url']),
            ],
        ];

        foreach ($types as $type) {
            DB::table('rest_api_authentication_types')->updateOrInsert(
                ['name' => $type['name']],
                array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
