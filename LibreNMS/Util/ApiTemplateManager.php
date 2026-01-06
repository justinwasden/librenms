<?php

namespace LibreNMS\Util;

use App\Models\ApiAuthSchema;
use App\Models\ApiTemplate;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Manages API templates for vendor device connections
 * Templates are loaded from:
 * 1. Database tables (api_templates, api_auth_schemas) - primary source
 * 2. YAML files in resources/definitions/api-templates/ (fallback)
 * 3. Hardcoded defaults as last resort fallback
 */
class ApiTemplateManager
{
    private static ?array $templatesCache = null;
    private static ?array $authTypesCache = null;
    private static string $templatesPath = '';

    /**
     * Get the path to template YAML files
     */
    private static function getTemplatesPath(): string
    {
        if (empty(self::$templatesPath)) {
            // Try Laravel's base_path helper if available and app is booted
            try {
                if (function_exists('base_path') && app()->bound('path')) {
                    self::$templatesPath = base_path('resources/definitions/api-templates');
                } else {
                    throw new \Exception('Laravel not booted');
                }
            } catch (\Throwable $e) {
                // Fallback for non-Laravel contexts or when app not fully booted
                self::$templatesPath = dirname(__DIR__, 2) . '/resources/definitions/api-templates';
            }
        }
        return self::$templatesPath;
    }

    /**
     * Check if database tables exist and are accessible
     */
    private static function databaseAvailable(): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }
            return Schema::hasTable('api_templates') && Schema::hasTable('api_auth_schemas');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Load templates from database
     */
    private static function loadTemplatesFromDatabase(): array
    {
        if (! self::databaseAvailable()) {
            return [];
        }

        try {
            $templates = [];
            $dbTemplates = ApiTemplate::with('endpoints')->where('enabled', true)->get();

            foreach ($dbTemplates as $template) {
                $templates[$template->key] = [
                    'name' => $template->name,
                    'description' => $template->description,
                    'os' => $template->os_types ?? [],
                    'auth_type' => $template->auth_type,
                    'base_url_pattern' => $template->base_url_pattern,
                    'capabilities' => $template->capabilities ?? [],
                    'is_system' => $template->is_system,
                    'endpoints' => $template->endpoints
                        ->where('enabled', true)
                        ->map(fn ($ep) => [
                            'capability' => $ep->capability,
                            'method' => $ep->method,
                            'path' => $ep->path,
                            'transform' => $ep->transform,
                            'for_each' => $ep->for_each,
                            'body' => $ep->body,
                            'headers' => $ep->headers,
                        ])
                        ->values()
                        ->toArray(),
                ];
            }

            return $templates;
        } catch (\Throwable $e) {
            \Log::warning('Failed to load API templates from database: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load auth schemas from database
     */
    private static function loadAuthSchemasFromDatabase(): array
    {
        if (! self::databaseAvailable()) {
            return [];
        }

        try {
            $schemas = [];
            $dbSchemas = ApiAuthSchema::all();

            foreach ($dbSchemas as $schema) {
                $schemas[$schema->key] = [
                    'name' => $schema->name,
                    'description' => $schema->description,
                    'fields' => $schema->fields ?? [],
                    'is_system' => $schema->is_system,
                ];
            }

            return $schemas;
        } catch (\Throwable $e) {
            \Log::warning('Failed to load API auth schemas from database: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load a template from YAML file
     */
    private static function loadTemplateFromYaml(string $key): ?array
    {
        $path = self::getTemplatesPath() . '/' . $key . '.yaml';

        if (! file_exists($path)) {
            return null;
        }

        try {
            $template = Yaml::parseFile($path);
            if (! is_array($template)) {
                return null;
            }

            return $template;
        } catch (\Exception $e) {
            \Log::warning("Failed to parse API template YAML: $path - " . $e->getMessage());

            return null;
        }
    }

    /**
     * Get all available API templates with their endpoints
     * Priority: Database > YAML files > Hardcoded defaults
     */
    public static function getAllTemplates(): array
    {
        if (self::$templatesCache !== null) {
            return self::$templatesCache;
        }

        $templates = [];

        // 1. Try to load from database first
        $templates = self::loadTemplatesFromDatabase();

        // 2. If no database templates, try YAML files
        if (empty($templates)) {
            $yamlPath = self::getTemplatesPath();
            if (is_dir($yamlPath)) {
                $files = glob($yamlPath . '/*.yaml');
                foreach ($files as $file) {
                    $key = basename($file, '.yaml');
                    $template = self::loadTemplateFromYaml($key);
                    if ($template) {
                        $templates[$key] = $template;
                    }
                }
            }
        }

        // 3. If still no templates, use hardcoded defaults
        if (empty($templates)) {
            $templates = self::getHardcodedTemplates();
        }

        self::$templatesCache = $templates;

        return $templates;
    }

    /**
     * Clear the template cache (useful after editing YAML files or database changes)
     */
    public static function clearCache(): void
    {
        self::$templatesCache = null;
        self::$authTypesCache = null;
    }

    /**
     * Get hardcoded default templates (fallback if no database or YAML files exist)
     */
    private static function getHardcodedTemplates(): array
    {
        return [
            'purestorage_flasharray' => [
                'name' => 'Pure Storage FlashArray',
                'description' => 'Login via API token to obtain session header and poll FlashArray endpoints.',
                'os' => ['purestorage'],
                'auth_type' => 'purestorage_api_token_login',
                'base_url_pattern' => 'https://{hostname}/api/2.26',
                'capabilities' => ['sensors', 'inventory', 'ports', 'ipv4', 'storage', 'ports_statistics', 'transceivers'],
                'endpoints' => [
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays',              'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\ArraySensors::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'arrays/performance',  'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\ArraySensors::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hardware?filter=type%3D%27controller%27', 'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Hardware::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hardware',            'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Hardware::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'drives',              'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Hardware::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'alerts',              'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Alerts::normalize'],
                    ['capability' => 'ports',     'method' => 'GET', 'path' => 'network-interfaces',  'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\NetworkInterfaces::normalize'],
                    ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'network-interfaces',  'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Ipv4::normalize'],
                    ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'network-interfaces/performance', 'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\NetworkPerformanceToPortsStats::normalize'],
                    ['capability' => 'transceivers', 'method' => 'GET', 'path' => 'network-interfaces/port-details', 'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\PortOptics::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes',             'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Volumes::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'volumes/performance', 'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Volumes::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'hosts',               'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Hosts::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'connections',         'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Connections::normalize'],
                    ['capability' => 'storage',   'method' => 'GET', 'path' => 'arrays',              'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\StorageDetails::normalize'],
                ],
            ],
            'proxmox_ve' => [
                'name' => 'Proxmox VE Node',
                'description' => 'Proxmox node endpoints for status, network, storage.',
                'os' => ['proxmox'],
                'auth_type' => 'proxmox_token',
                'base_url_pattern' => 'https://{hostname}:8006/api2/json',
                'capabilities' => ['sensors', 'mempools', 'processors', 'ports', 'inventory', 'ipv4', 'ports_statistics'],
                'endpoints' => [
                    ['capability' => 'sensors',    'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\NodeStatus::normalize'],
                    ['capability' => 'mempools',   'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\NodeStatus::normalize'],
                    ['capability' => 'processors', 'method' => 'GET', 'path' => 'nodes/{node}/status',  'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\NodeStatus::normalize'],
                    ['capability' => 'ports',      'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\NodeNetwork::normalize'],
                    ['capability' => 'inventory',  'method' => 'GET', 'path' => 'nodes/{node}/storage', 'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\StorageStatus::normalize'],
                    ['capability' => 'ipv4',       'method' => 'GET', 'path' => 'nodes/{node}/network', 'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\Ipv4::normalize'],
                    ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => 'nodes/{node}/rrddata?timeframe=hour', 'transform' => 'LibreNMS\\Util\\Normalizers\\Proxmox\\NetworkStatistics::normalize'],
                ],
            ],
            'fortinet_fortigate' => [
                'name' => 'Fortinet FortiGate',
                'description' => 'FortiGate REST v2 API',
                'os' => ['fortigate'],
                'auth_type' => 'bearer',
                'base_url_pattern' => 'https://{hostname}/api/v2',
                'capabilities' => ['sensors', 'inventory', 'ports', 'ipv4', 'vlans', 'routes', 'dhcp-leases', 'vpn-ssl-stats'],
                'endpoints' => [
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/resource/usage', 'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\SystemUsage::normalize'],
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'monitor/system/status',         'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\SystemStatus::normalize'],
                    ['capability' => 'ports',     'method' => 'GET', 'path' => 'monitor/system/interface',      'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\Interfaces::normalize'],
                    ['capability' => 'ipv4',      'method' => 'GET', 'path' => 'monitor/system/interface',      'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\Ipv4::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/sensor-info',    'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\SensorInfo::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ipsec',             'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\VpnIpsec::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/vpn/ssl',               'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\VpnSsl::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/system/dhcp',           'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\Dhcp::normalize'],
                    ['capability' => 'sensors',   'method' => 'GET', 'path' => 'monitor/license/status',        'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\License::normalize'],
                    ['capability' => 'vlans',     'method' => 'GET', 'path' => 'cmdb/system/interface',         'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\Vlans::normalize'],
                    ['capability' => 'routes',    'method' => 'GET', 'path' => 'monitor/router/ipv4',           'transform' => 'LibreNMS\\Util\\Normalizers\\Fortinet\\Routes::normalize'],
                ],
            ],
            'vmware_vcenter' => [
                'name' => 'VMware vCenter',
                'description' => 'VMware vCenter Server REST API',
                'os' => ['vmware-vcsa'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['clusters', 'hypervisor_hosts', 'vminfo', 'vlans', 'sensors', 'inventory'],
                'endpoints' => [
                    ['capability' => 'clusters',         'method' => 'GET', 'path' => '/rest/vcenter/cluster',   'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\Clusters::normalize'],
                    ['capability' => 'hypervisor_hosts', 'method' => 'GET', 'path' => '/rest/vcenter/host',      'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\Hosts::normalize'],
                    ['capability' => 'vminfo',           'method' => 'GET', 'path' => '/rest/vcenter/vm',        'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\VmInfo::normalize'],
                    ['capability' => 'vlans',            'method' => 'GET', 'path' => '/rest/vcenter/network',   'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\Network::normalize'],
                    ['capability' => 'sensors',          'method' => 'GET', 'path' => '/rest/appliance/health',  'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\ApplianceHealth::normalize'],
                ],
            ],
            'vcenter_soap' => [
                'name' => 'VMware vCenter (SOAP)',
                'description' => 'VMware vCenter Server SOAP API for detailed VM/Host metrics',
                'os' => ['vmware-vcsa'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['clusters', 'hypervisor_hosts', 'vminfo', 'vlans', 'sensors', 'inventory'],
                'endpoints' => [],
            ],
            'vmware_esxi' => [
                'name' => 'VMware ESXi',
                'description' => 'VMware ESXi SOAP/REST API',
                'os' => ['vmware-esxi'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['sensors', 'inventory', 'vminfo', 'vlans', 'processors', 'mempools', 'storage'],
                'endpoints' => [],
            ],
            'esxi_soap' => [
                'name' => 'VMware ESXi (SOAP)',
                'description' => 'VMware ESXi SOAP API',
                'os' => ['vmware-esxi'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['sensors', 'inventory', 'vminfo', 'vlans', 'processors', 'mempools', 'storage'],
                'endpoints' => [],
            ],
            'vmware_velocloud' => [
                'name' => 'VMware VeloCloud',
                'description' => 'VeloCloud SD-WAN API',
                'os' => ['velocloud', 'vmware-sdwan'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['ports', 'sensors', 'inventory'],
                'endpoints' => [
                    ['capability' => 'ports',     'method' => 'POST', 'path' => '/portal/rest/edge/getEdge',           'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\VeloCloudPorts::normalize'],
                    ['capability' => 'sensors',   'method' => 'POST', 'path' => '/portal/rest/edge/getEdgeSDWANPeers', 'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\VeloCloudSensors::normalize'],
                    ['capability' => 'inventory', 'method' => 'POST', 'path' => '/portal/rest/edge/getEdge',           'transform' => 'LibreNMS\\Util\\Normalizers\\VMware\\VeloCloudInventory::normalize'],
                ],
            ],
            'netapp_ontap' => [
                'name' => 'NetApp ONTAP API',
                'description' => 'Template for NetApp ONTAP REST API, providing discovery and metrics for ports, storage, and inventory.',
                'os' => ['netapp'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}/api',
                'capabilities' => ['inventory', 'ports', 'ipv4', 'storage', 'sensors', 'ports_statistics'],
                'endpoints' => [
                    ['capability' => 'inventory',       'method' => 'GET', 'path' => '/cluster/nodes',                                       'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\ClusterNodes::normalize'],
                    ['capability' => 'ports',           'method' => 'GET', 'path' => '/network/ethernet/ports',                              'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\NetworkPorts::normalize'],
                    ['capability' => 'ipv4',            'method' => 'GET', 'path' => '/network/ip/interfaces',                               'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\Ipv4::normalize'],
                    ['capability' => 'storage',         'method' => 'GET', 'path' => '/storage/volumes',                                     'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\Volumes::normalize'],
                    ['capability' => 'sensors',         'method' => 'GET', 'path' => '/cluster/nodes?fields=statistics.processor_utilization_raw', 'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\ClusterMetrics::normalize'],
                    ['capability' => 'ports_statistics', 'method' => 'GET', 'path' => '/network/ethernet/ports/{port_uuid}/metrics',         'transform' => 'LibreNMS\\Util\\Normalizers\\NetApp\\PortMetrics::normalize', 'for_each' => 'ports'],
                ],
            ],
            'cisco_ucsm' => [
                'name' => 'Cisco UCS Manager',
                'description' => 'Cisco UCS Manager XML API',
                'os' => ['cisco-ucsm', 'cisco-usm'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['inventory', 'sensors', 'ports', 'processors', 'mempools', 'vlans', 'ipv4'],
                'endpoints' => [],
            ],
            'cisco_ftd' => [
                'name' => 'Cisco FTD',
                'description' => 'Cisco Firepower Threat Defense API',
                'os' => ['cisco-ftd', 'ftd'],
                'auth_type' => 'oauth2',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['inventory', 'ports', 'sensors'],
                'endpoints' => [
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => '/api/fdm/latest/devices/default', 'transform' => 'LibreNMS\\Util\\Normalizers\\Cisco\\FtdInventory::normalize'],
                    ['capability' => 'ports',     'method' => 'GET', 'path' => '/api/fdm/latest/devices/default/interfaces', 'transform' => 'LibreNMS\\Util\\Normalizers\\Cisco\\FtdInterfaces::normalize'],
                ],
            ],
        ];
    }

    /**
     * Load a specific template by key
     */
    public static function loadTemplate(string $key): ?array
    {
        $templates = self::getAllTemplates();
        return $templates[$key] ?? null;
    }

    /**
     * Get templates filtered by device OS
     */
    public static function getTemplatesForOs(string $os): array
    {
        $allTemplates = self::getAllTemplates();
        $matched = [];

        foreach ($allTemplates as $vendor => $template) {
            if (in_array($os, $template['os'] ?? [])) {
                $matched[$vendor] = $template;
            }
        }

        return $matched;
    }

    /**
     * Get the first matching template for a device OS
     */
    public static function getDefaultTemplateForOs(string $os): ?array
    {
        $templates = self::getTemplatesForOs($os);
        if (empty($templates)) {
            return null;
        }

        $key = array_key_first($templates);
        return array_merge(['key' => $key], $templates[$key]);
    }

    /**
     * Get available authentication types
     * Priority: Database > Hardcoded defaults
     */
    public static function getAuthTypes(): array
    {
        if (self::$authTypesCache !== null) {
            return self::$authTypesCache;
        }

        // Try database first
        $schemas = self::loadAuthSchemasFromDatabase();

        // Fallback to hardcoded if no database schemas
        if (empty($schemas)) {
            $schemas = self::getHardcodedAuthTypes();
        }

        self::$authTypesCache = $schemas;

        return self::$authTypesCache;
    }

    /**
     * Get hardcoded auth types (fallback)
     */
    private static function getHardcodedAuthTypes(): array
    {
        return [
            'basic' => [
                'name' => 'Basic Authentication',
                'description' => 'Username and password authentication',
                'fields' => [
                    [
                        'name' => 'api_credential_username',
                        'label' => 'Username',
                        'type' => 'text',
                        'required' => true,
                        'encrypted' => false,
                        'placeholder' => 'admin',
                    ],
                    [
                        'name' => 'api_credential_password',
                        'label' => 'Password',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter password',
                    ],
                ],
            ],
            'bearer' => [
                'name' => 'Bearer Token',
                'description' => 'Bearer token authentication (Authorization header)',
                'fields' => [
                    [
                        'name' => 'api_credential_api_token',
                        'label' => 'API Token',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter API token',
                    ],
                ],
            ],
            'token' => [
                'name' => 'API Token (Header)',
                'description' => 'Token-based authentication via custom header',
                'fields' => [
                    [
                        'name' => 'api_credential_api_token',
                        'label' => 'API Token',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter API token',
                    ],
                ],
            ],
            'purestorage_api_token_login' => [
                'name' => 'Pure Storage API Token',
                'description' => 'Login via API token to obtain session header',
                'fields' => [
                    [
                        'name' => 'api_credential_api_token',
                        'label' => 'API Token',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter Pure Storage API token',
                    ],
                    [
                        'name' => 'api_credential_login_path',
                        'label' => 'Login Path',
                        'type' => 'text',
                        'required' => false,
                        'encrypted' => false,
                        'placeholder' => '/login',
                        'default' => '/login',
                    ],
                    [
                        'name' => 'api_credential_auth_header_name',
                        'label' => 'Auth Header Name',
                        'type' => 'text',
                        'required' => false,
                        'encrypted' => false,
                        'placeholder' => 'X-Auth-Token',
                        'default' => 'X-Auth-Token',
                    ],
                ],
            ],
            'proxmox_token' => [
                'name' => 'Proxmox API Token',
                'description' => 'Proxmox VE API token authentication',
                'fields' => [
                    [
                        'name' => 'api_credential_token_user',
                        'label' => 'Token User',
                        'type' => 'text',
                        'required' => true,
                        'encrypted' => false,
                        'placeholder' => 'user@pve',
                    ],
                    [
                        'name' => 'api_credential_token_id',
                        'label' => 'Token ID',
                        'type' => 'text',
                        'required' => true,
                        'encrypted' => false,
                        'placeholder' => 'monitoring',
                    ],
                    [
                        'name' => 'api_credential_token_secret',
                        'label' => 'Token Secret',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter token secret',
                    ],
                ],
            ],
            'oauth2' => [
                'name' => 'OAuth 2.0',
                'description' => 'OAuth 2.0 client credentials flow',
                'fields' => [
                    [
                        'name' => 'api_credential_client_id',
                        'label' => 'Client ID',
                        'type' => 'text',
                        'required' => true,
                        'encrypted' => false,
                        'placeholder' => 'client_id',
                    ],
                    [
                        'name' => 'api_credential_client_secret',
                        'label' => 'Client Secret',
                        'type' => 'password',
                        'required' => true,
                        'encrypted' => true,
                        'placeholder' => 'Enter client secret',
                    ],
                    [
                        'name' => 'api_credential_token_endpoint',
                        'label' => 'Token Endpoint',
                        'type' => 'text',
                        'required' => false,
                        'encrypted' => false,
                        'placeholder' => '/api/fdm/latest/fdm/token',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get authentication fields for a specific auth type
     */
    public static function getAuthFields(string $authType): array
    {
        $authTypes = self::getAuthTypes();
        return $authTypes[$authType]['fields'] ?? [];
    }

    /**
     * Get endpoints for a template
     */
    public static function getTemplateEndpoints(string $templateKey): array
    {
        $template = self::loadTemplate($templateKey);
        return $template['endpoints'] ?? [];
    }

    /**
     * Validate a template structure
     */
    public static function validateTemplate(array $template): bool
    {
        $required = ['name', 'auth_type'];

        foreach ($required as $field) {
            if (!isset($template[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of available template keys
     */
    public static function getTemplateKeys(): array
    {
        return array_keys(self::getAllTemplates());
    }

    /**
     * Check if a template exists
     */
    public static function templateExists(string $key): bool
    {
        return isset(self::getAllTemplates()[$key]);
    }
}
