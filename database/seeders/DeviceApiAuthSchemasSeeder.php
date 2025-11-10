<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DeviceApiAuthSchema;
use App\Models\DeviceApiAuthSchemaField;
use App\Models\DeviceApiTemplate;
use App\Models\DeviceApiTemplateEndpoint;

class DeviceApiAuthSchemasSeeder extends Seeder
{
    public function run(): void
    {
        $this->createAuthSchemas();
        $this->createTemplates();
    }

    private function createAuthSchemas(): void
    {
        // Helpers
        $schema = function (array $attrs) {
            return DeviceApiAuthSchema::firstOrCreate(
                ['key' => $attrs['key']],
                $attrs
            );
        };
        $upsertField = function ($schemaId, array $attrs) {
            return DeviceApiAuthSchemaField::updateOrCreate(
                ['schema_id' => $schemaId, 'name' => $attrs['name']],
                array_merge($attrs, ['schema_id' => $schemaId])
            );
        };
        $renameField = function (string $schemaKey, string $from, string $to) {
            $schemaModel = DeviceApiAuthSchema::where('key', $schemaKey)->first();
            if (!$schemaModel) return;
            $existing = DeviceApiAuthSchemaField::where('schema_id', $schemaModel->id)
                ->where('name', $from)->first();
            if ($existing && $from !== $to) {
                $existing->update(['name' => $to]);
            }
        };

        // Bearer
        $bearer = $schema([
            'key' => 'bearer',
            'label' => 'Bearer Token',
            'description' => 'Authorization: Bearer <access_token>',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $renameField('bearer', 'api_token', 'access_token');
        $upsertField($bearer->id, [
            'name' => 'access_token',
            'label' => 'Access Token',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Paste the access token',
            'display_order' => 1,
        ]);

        // API Key fixed header
        $apikey = $schema([
            'key' => 'apikey',
            'label' => 'API Key',
            'description' => 'API Key in X-API-Key header',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($apikey->id, [
            'name' => 'api_key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your API key',
            'display_order' => 1,
        ]);

        // API Key custom header
        $apikeyHeader = $schema([
            'key' => 'apikey_custom_header',
            'label' => 'API Key (Custom Header)',
            'description' => 'API key in a custom header (e.g. X-Auth-Token)',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($apikeyHeader->id, [
            'name' => 'api_key_header_name',
            'label' => 'Header Name',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'e.g. X-Auth-Token',
            'display_order' => 1,
        ]);
        $upsertField($apikeyHeader->id, [
            'name' => 'api_key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your API key',
            'display_order' => 2,
        ]);

        // API Key query param
        $apikeyQuery = $schema([
            'key' => 'apikey_query',
            'label' => 'API Key (Query Param)',
            'description' => 'API key sent as a query parameter',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($apikeyQuery->id, [
            'name' => 'api_key_param_name',
            'label' => 'Parameter Name',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'e.g. api_key',
            'display_order' => 1,
        ]);
        $upsertField($apikeyQuery->id, [
            'name' => 'api_key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Enter your API key',
            'display_order' => 2,
        ]);

        // Basic
        $basic = $schema([
            'key' => 'basic',
            'label' => 'Basic Authentication',
            'description' => 'HTTP Basic Auth (username/password)',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($basic->id, [
            'name' => 'username',
            'label' => 'Username',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'Username',
            'display_order' => 1,
        ]);
        $upsertField($basic->id, [
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Password',
            'display_order' => 2,
        ]);

        // Digest
        $digest = $schema([
            'key' => 'digest',
            'label' => 'Digest Authentication',
            'description' => 'HTTP Digest authentication',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($digest->id, [
            'name' => 'username',
            'label' => 'Username',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'Username',
            'display_order' => 1,
        ]);
        $upsertField($digest->id, [
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Password',
            'display_order' => 2,
        ]);
        $upsertField($digest->id, [
            'name' => 'realm',
            'label' => 'Realm',
            'type' => 'text',
            'required' => false,
            'encrypted' => false,
            'placeholder' => 'Optional realm',
            'display_order' => 3,
        ]);

        // NTLM
        $ntlm = $schema([
            'key' => 'ntlm',
            'label' => 'NTLM Authentication',
            'description' => 'Windows NTLM authentication',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($ntlm->id, [
            'name' => 'username',
            'label' => 'Username',
            'type' => 'text',
            'required' => true,
            'encrypted' => false,
            'placeholder' => 'user',
            'display_order' => 1,
        ]);
        $upsertField($ntlm->id, [
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Password',
            'display_order' => 2,
        ]);
        $upsertField($ntlm->id, [
            'name' => 'domain',
            'label' => 'Domain',
            'type' => 'text',
            'required' => false,
            'encrypted' => false,
            'placeholder' => 'DOMAIN (optional)',
            'display_order' => 3,
        ]);

        // OAuth2 Client Credentials
        $oauthCC = $schema([
            'key' => 'oauth2_client_credentials',
            'label' => 'OAuth2 (Client Credentials)',
            'description' => 'Access token via client_credentials grant',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($oauthCC->id, ['name' => 'token_url','label' => 'Token URL','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'https://auth.example.com/oauth/token','display_order' => 1]);
        $upsertField($oauthCC->id, ['name' => 'client_id','label' => 'Client ID','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'Client ID','display_order' => 2]);
        $upsertField($oauthCC->id, ['name' => 'client_secret','label' => 'Client Secret','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Client Secret','display_order' => 3]);
        $upsertField($oauthCC->id, ['name' => 'scope','label' => 'Scope','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'space-delimited scopes (optional)','display_order' => 4]);
        $upsertField($oauthCC->id, ['name' => 'audience','label' => 'Audience','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'Optional audience','display_order' => 5]);

        // OAuth2 Password Grant
        $oauthPwd = $schema([
            'key' => 'oauth2_password',
            'label' => 'OAuth2 (Password Grant)',
            'description' => 'Access token via username/password',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($oauthPwd->id, ['name' => 'token_url','label' => 'Token URL','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'https://auth.example.com/oauth/token','display_order' => 1]);
        $upsertField($oauthPwd->id, ['name' => 'username','label' => 'Username','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'Username','display_order' => 2]);
        $upsertField($oauthPwd->id, ['name' => 'password','label' => 'Password','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Password','display_order' => 3]);
        $upsertField($oauthPwd->id, ['name' => 'client_id','label' => 'Client ID','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'Client ID (optional)','display_order' => 4]);
        $upsertField($oauthPwd->id, ['name' => 'client_secret','label' => 'Client Secret','type' => 'password','required' => false,'encrypted' => true,'placeholder' => 'Client Secret (optional)','display_order' => 5]);
        $upsertField($oauthPwd->id, ['name' => 'scope','label' => 'Scope','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'space-delimited scopes (optional)','display_order' => 6]);

        // OAuth2 Authorization Code
        $oauthAC = $schema([
            'key' => 'oauth2_authorization_code',
            'label' => 'OAuth2 (Authorization Code)',
            'description' => 'Authorization Code flow with optional refresh',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($oauthAC->id, ['name' => 'authorization_url','label' => 'Authorization URL','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'https://auth.example.com/oauth/authorize','display_order' => 1]);
        $upsertField($oauthAC->id, ['name' => 'token_url','label' => 'Token URL','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'https://auth.example.com/oauth/token','display_order' => 2]);
        $upsertField($oauthAC->id, ['name' => 'client_id','label' => 'Client ID','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'Client ID','display_order' => 3]);
        $upsertField($oauthAC->id, ['name' => 'client_secret','label' => 'Client Secret','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Client Secret','display_order' => 4]);
        $upsertField($oauthAC->id, ['name' => 'redirect_uri','label' => 'Redirect URI','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'https://app.example.com/callback','display_order' => 5]);
        $upsertField($oauthAC->id, ['name' => 'scope','label' => 'Scope','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'space-delimited scopes (optional)','display_order' => 6]);
        $upsertField($oauthAC->id, ['name' => 'code','label' => 'Authorization Code','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'Optional if interactive flow','display_order' => 7]);
        $upsertField($oauthAC->id, ['name' => 'refresh_token','label' => 'Refresh Token','type' => 'password','required' => false,'encrypted' => true,'placeholder' => 'Optional refresh token','display_order' => 8]);

        // JWT (static)
        $jwt = $schema([
            'key' => 'jwt_static',
            'label' => 'JWT (Static Token)',
            'description' => 'Pre-issued JWT used in Authorization header',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $renameField('jwt_static', 'jwt_token', 'jwt');
        $upsertField($jwt->id, [
            'name' => 'jwt',
            'label' => 'JWT',
            'type' => 'password',
            'required' => true,
            'encrypted' => true,
            'placeholder' => 'Paste the JWT',
            'display_order' => 1,
        ]);

        // Cookie
        $cookie = $schema([
            'key' => 'cookie',
            'label' => 'Cookie Session',
            'description' => 'Send a cookie with each request',
            'vendor' => 'generic',
            'enabled' => true,
        ]);
        $upsertField($cookie->id, ['name' => 'cookie_name','label' => 'Cookie Name','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'e.g. sessionid','display_order' => 1]);
        $upsertField($cookie->id, ['name' => 'cookie_value','label' => 'Cookie Value','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Cookie value','display_order' => 2]);

        // AWS SigV4
        $aws = $schema([
            'key' => 'aws_sigv4',
            'label' => 'AWS Signature V4',
            'description' => 'Sign requests with AWS Signature Version 4',
            'vendor' => 'aws',
            'enabled' => true,
        ]);
        $upsertField($aws->id, ['name' => 'aws_access_key_id','label' => 'Access Key ID','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'AKIA...','display_order' => 1]);
        $upsertField($aws->id, ['name' => 'aws_secret_access_key','label' => 'Secret Access Key','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Secret Access Key','display_order' => 2]);
        $upsertField($aws->id, ['name' => 'aws_region','label' => 'Region','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'us-east-1','display_order' => 3]);
        $upsertField($aws->id, ['name' => 'aws_service','label' => 'Service','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'execute-api','display_order' => 4]);
        $upsertField($aws->id, ['name' => 'aws_session_token','label' => 'Session Token','type' => 'password','required' => false,'encrypted' => true,'placeholder' => 'Optional STS token','display_order' => 5]);

        // Proxmox Token
        $pxToken = $schema([
            'key' => 'proxmox_token',
            'label' => 'Proxmox API Token',
            'description' => 'Proxmox VE API Token authentication',
            'vendor' => 'proxmox',
            'enabled' => true,
        ]);
        $upsertField($pxToken->id, ['name' => 'token_user','label' => 'Token User@Realm','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'user@pve','display_order' => 1]);
        $upsertField($pxToken->id, ['name' => 'token_id','label' => 'Token ID','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'tokenid','display_order' => 2]);
        $upsertField($pxToken->id, ['name' => 'token_secret','label' => 'Token Secret','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Secret value','display_order' => 3]);

        // Proxmox Ticket
        $pxTicket = $schema([
            'key' => 'proxmox_ticket',
            'label' => 'Proxmox Username/Password',
            'description' => 'Proxmox VE username/password authentication',
            'vendor' => 'proxmox',
            'enabled' => true,
        ]);
        $upsertField($pxTicket->id, ['name' => 'username','label' => 'Username@Realm','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'root@pam','display_order' => 1]);
        $upsertField($pxTicket->id, ['name' => 'password','label' => 'Password','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Password','display_order' => 2]);

        // VMware vCenter Session
        $vcenter = $schema([
            'key' => 'vmware_vcenter_session',
            'label' => 'VMware vCenter Session',
            'description' => 'vCenter API session via username/password',
            'vendor' => 'vmware',
            'enabled' => true,
        ]);
        $upsertField($vcenter->id, ['name' => 'username','label' => 'Username','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'administrator@vsphere.local','display_order' => 1]);
        $upsertField($vcenter->id, ['name' => 'password','label' => 'Password','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Password','display_order' => 2]);

        // Zabbix API Session
        $zabbix = $schema([
            'key' => 'zabbix_session',
            'label' => 'Zabbix API Session',
            'description' => 'Zabbix JSON-RPC session via user.login',
            'vendor' => 'zabbix',
            'enabled' => true,
        ]);
        $upsertField($zabbix->id, ['name' => 'username','label' => 'Username','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'Admin','display_order' => 1]);
        $upsertField($zabbix->id, ['name' => 'password','label' => 'Password','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Password','display_order' => 2]);

        // PureStorage login -> header exchange
        $pureLogin = $schema([
            'key' => 'purestorage_api_token_login',
            'label' => 'PureStorage API Token Login',
            'description' => 'POST api_token to /login, read X-Auth-Token header, use on subsequent requests',
            'vendor' => 'purestorage',
            'enabled' => true,
        ]);
        $upsertField($pureLogin->id, ['name' => 'api_token','label' => 'API Token','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Enter your API token','display_order' => 1]);
        $upsertField($pureLogin->id, ['name' => 'login_path','label' => 'Login Path','type' => 'text','required' => true,'encrypted' => false,'placeholder' => '/login','display_order' => 2]);
        $upsertField($pureLogin->id, ['name' => 'auth_header_name','label' => 'Auth Header Name','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'X-Auth-Token','display_order' => 3]);
        $upsertField($pureLogin->id, ['name' => 'auth_header_prefix','label' => 'Auth Header Prefix','type' => 'text','required' => false,'encrypted' => false,'placeholder' => 'Optional (e.g., Token )','display_order' => 4]);

        // Check Point Management API session (custom)
        $checkpoint = $schema([
            'key' => 'checkpoint_session',
            'label' => 'Check Point Session',
            'description' => 'Login session via /login, then send X-chkp-sid header',
            'vendor' => 'checkpoint',
            'enabled' => true,
        ]);
        $upsertField($checkpoint->id, ['name' => 'login_path','label' => 'Login Path','type' => 'text','required' => true,'encrypted' => false,'placeholder' => '/login','display_order' => 1]);
        $upsertField($checkpoint->id, ['name' => 'username','label' => 'Username','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'admin','display_order' => 2]);
        $upsertField($checkpoint->id, ['name' => 'password','label' => 'Password','type' => 'password','required' => true,'encrypted' => true,'placeholder' => 'Password','display_order' => 3]);
        $upsertField($checkpoint->id, ['name' => 'sid_header_name','label' => 'SID Header Name','type' => 'text','required' => true,'encrypted' => false,'placeholder' => 'X-chkp-sid','display_order' => 4]);
    }

    private function createTemplates(): void
    {
        // Keep any DB-backed template creation you need here.
        // This section previously created PureStorage, Proxmox, vCenter etc. from models directly.
        // If these are already covered by DeviceApiTemplatesSeeder, you may remove duplicates or leave this as-is.

        $upsertEndpoint = function ($templateId, array $e) {
            return DeviceApiTemplateEndpoint::updateOrCreate(
                [
                    'template_id' => $templateId,
                    'capability' => $e['capability'],
                    'path'       => $e['path'],
                ],
                [
                    'method'        => $e['method'] ?? 'GET',
                    'enabled'       => $e['enabled'] ?? true,
                    'transform'     => $e['transform'] ?? null,
                    'display_order' => $e['order'] ?? 1,
                    'headers'       => $e['headers'] ?? [],
                    'request_body'  => $e['request_body'] ?? null,
                ]
            );
        };

        // Example: (If retaining DB model-based seeding for Pure and Proxmox)
        // Pure Storage FlashArray (login schema)
        $pureSchema = DeviceApiAuthSchema::where('key', 'purestorage_api_token_login')->first();
        $pureTemplate = DeviceApiTemplate::updateOrCreate(
            ['key' => 'purestorage_flasharray'],
            [
                'label'        => 'Pure Storage FlashArray',
                'os_keys'      => ['purestorage'],
                'schema_id'    => $pureSchema?->id,
                'default_values' => [
                    'base_url_pattern' => 'https://{hostname}/api/2.26',
                    'login_path'       => '/login',
                    'auth_header_name' => 'X-Auth-Token',
                ],
                'modules'      => ['ports', 'sensors', 'inventory'],
                'capabilities' => ['ports', 'sensors', 'inventory'],
                'description'  => 'Pure Storage FlashArray REST API v2.x',
                'enabled'      => true,
            ]
        );
        foreach ([
            ['capability' => 'ports',   'path' => '/network-interfaces',             'transform' => 'normalizePureNetworkInterfaces',   'order' => 1],
            ['capability' => 'ports',   'path' => '/network-interfaces/performance', 'transform' => 'normalizePureNetworkPerformance',   'order' => 2],
            ['capability' => 'sensors', 'path' => '/hardware',                       'transform' => 'normalizePureHardware',             'order' => 1],
            ['capability' => 'sensors', 'path' => '/ports',                          'transform' => 'normalizePurePortOptics',           'order' => 2],
            ['capability' => 'sensors', 'path' => '/arrays',                         'transform' => 'normalizePureArraySensors',         'order' => 3],
            ['capability' => 'sensors', 'path' => '/arrays/performance',             'transform' => 'normalizePureArraySensors',         'order' => 4],
            ['capability' => 'sensors', 'path' => '/volumes',                        'transform' => 'normalizePureVolumes',              'order' => 5],
            ['capability' => 'sensors', 'path' => '/volumes/performance',            'transform' => 'normalizePureVolumes',              'order' => 6],
            ['capability' => 'inventory', 'path' => '/hardware',                     'transform' => 'normalizePureHardware',             'order' => 1],
            ['capability' => 'inventory', 'path' => '/hosts',                        'transform' => 'normalizePureHosts',                'order' => 2],
            ['capability' => 'storage', 'path' => '/volumes', 											 'transform' => 'normalizePureVolumesToStorage', 'order' => 5],
        ] as $e) { $upsertEndpoint($pureTemplate->id, $e); }

        // Proxmox VE (Token)
        $pxTokenSchema = DeviceApiAuthSchema::where('key', 'proxmox_token')->first();
        $pxTokenTemplate = DeviceApiTemplate::updateOrCreate(
            ['key' => 'proxmox_ve_token'],
            [
                'label'        => 'Proxmox VE (API Token)',
                'os_keys'      => ['proxmox'],
                'schema_id'    => $pxTokenSchema?->id,
                'default_values' => [
                    'base_url_pattern' => 'https://{hostname}:8006/api2/json',
                ],
                'modules'      => ['ports', 'sensors', 'processors', 'mempools', 'inventory', 'ipv4'],
                'capabilities' => ['ports', 'sensors', 'processors', 'mempools', 'inventory', 'ipv4'],
                'description'  => 'Proxmox VE with API Token auth',
                'enabled'      => true,
            ]
        );
        foreach ([
            ['capability' => 'sensors',    'path' => '/nodes/{node}/status',   'transform' => 'normalizeProxmoxNodeStatus',       'order' => 1],
            ['capability' => 'ports',      'path' => '/nodes/{node}/network',  'transform' => 'normalizeProxmoxNodeNetwork',       'order' => 2],
            ['capability' => 'ipv4',       'path' => '/nodes/{node}/network',  'transform' => 'normalizeProxmoxIpv4',              'order' => 3],
            ['capability' => 'inventory',  'path' => '/storage',               'transform' => 'normalizeProxmoxNodeStorage',       'order' => 4],
            ['capability' => 'sensors',    'path' => '/cluster/status',        'transform' => 'normalizeProxmoxClusterStatus',     'order' => 5],
            ['capability' => 'sensors',    'path' => '/cluster/resources',     'transform' => 'normalizeProxmoxClusterResources',  'order' => 6],
        ] as $e) { $upsertEndpoint($pxTokenTemplate->id, $e); }
    }
}