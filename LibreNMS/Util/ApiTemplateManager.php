<?php

namespace LibreNMS\Util;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages API templates for vendor device connections
 * Templates are loaded from YAML files in resources/definitions/api-templates/
 * Users can edit these YAML files to customize endpoints without modifying PHP code
 */
class ApiTemplateManager
{
    private static ?array $templatesCache = null;
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
     * Load a template from YAML file
     */
    private static function loadTemplateFromYaml(string $key): ?array
    {
        $path = self::getTemplatesPath() . '/' . $key . '.yaml';

        if (!file_exists($path)) {
            return null;
        }

        try {
            $template = Yaml::parseFile($path);
            if (!is_array($template)) {
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
     * Loads from YAML files first, falls back to hardcoded defaults
     */
    public static function getAllTemplates(): array
    {
        if (self::$templatesCache !== null) {
            return self::$templatesCache;
        }

        $templates = [];

        // Scan for YAML template files
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

        // If no YAML files found, use hardcoded defaults
        if (empty($templates)) {
            $templates = self::getHardcodedTemplates();
        }

        self::$templatesCache = $templates;
        return $templates;
    }

    /**
     * Clear the template cache (useful after editing YAML files)
     */
    public static function clearCache(): void
    {
        self::$templatesCache = null;
    }

    /**
     * Get hardcoded default templates (fallback if no YAML files exist)
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
                    ['capability' => 'inventory', 'method' => 'GET', 'path' => 'controllers',         'transform' => 'LibreNMS\\Util\\Normalizers\\Pure\\Hardware::normalize'],
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
                'endpoints' => [
                    ['path' => '/api/vcenter/host', 'capability' => 'inventory', 'poll_interval' => 300],
                    ['path' => '/api/vcenter/vm', 'capability' => 'inventory', 'poll_interval' => 300],
                    ['path' => '/api/vcenter/datastore', 'capability' => 'storage', 'poll_interval' => 600],
                    ['path' => '/api/vcenter/network', 'capability' => 'ports', 'poll_interval' => 600],
                    ['path' => '/api/appliance/system/version', 'capability' => 'system', 'poll_interval' => 3600],
                    ['path' => '/api/appliance/monitoring', 'capability' => 'sensors', 'poll_interval' => 300],
                ],
            ],
            'vmware_esxi' => [
                'name' => 'VMware ESXi',
                'description' => 'VMware ESXi SOAP/REST API',
                'os' => ['vmware-esxi'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/api/vcenter/host', 'capability' => 'inventory', 'poll_interval' => 600],
                    ['path' => '/rest/appliance/system/version', 'capability' => 'system', 'poll_interval' => 3600],
                    ['path' => '/rest/appliance/health/system', 'capability' => 'sensors', 'poll_interval' => 300],
                ],
            ],
            'proxmox' => [
                'name' => 'Proxmox VE',
                'description' => 'Proxmox Virtual Environment API',
                'os' => ['proxmox'],
                'auth_type' => 'token',
                'base_url_pattern' => 'https://{hostname}:8006',
                'endpoints' => [
                    ['path' => '/api2/json/cluster/resources', 'capability' => 'inventory', 'poll_interval' => 300],
                    ['path' => '/api2/json/nodes', 'capability' => 'inventory', 'poll_interval' => 900],
                    ['path' => '/api2/json/cluster/status', 'capability' => 'system', 'poll_interval' => 300],
                    ['path' => '/api2/json/cluster/nextid', 'capability' => 'metrics', 'poll_interval' => 900],
                ],
            ],
            'purestorage' => [
                'name' => 'Pure Storage FlashArray',
                'description' => 'Pure Storage FlashArray REST API',
                'os' => ['purestorage'],
                'auth_type' => 'token',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/api/1.19/array', 'capability' => 'inventory', 'poll_interval' => 900],
                    ['path' => '/api/1.19/host', 'capability' => 'inventory', 'poll_interval' => 900],
                    ['path' => '/api/1.19/volume', 'capability' => 'storage', 'poll_interval' => 300],
                    ['path' => '/api/1.19/drive', 'capability' => 'sensors', 'poll_interval' => 300],
                ],
            ],
            'vmware_velocloud' => [
                'name' => 'VMware VeloCloud',
                'description' => 'VeloCloud SD-WAN API',
                'os' => ['velocloud', 'vmware-sdwan'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'capabilities' => ['dhcp-leases', 'vpn-ssl-stats'],
                'endpoints' => [
                    ['path' => '/api/v2/monitor/system/resource/usage', 'capability' => 'processors', 'poll_interval' => 300],
                    ['path' => '/api/v2/monitor/system/interface', 'capability' => 'ports', 'poll_interval' => 300],
                    ['path' => '/api/v2/monitor/system/firmware', 'capability' => 'inventory', 'poll_interval' => 3600],
                    ['path' => '/api/v2/monitor/system/ha-statistics', 'capability' => 'system', 'poll_interval' => 600],
                    ['path' => '/api/v2/monitor/firewall/session/select', 'capability' => 'metrics', 'poll_interval' => 300],
                ],
            ],
            'netapp_ontap' => [
                'name' => 'NetApp ONTAP API',
                'description' => 'Template for NetApp ONTAP REST API, providing discovery and metrics for ports, storage, and inventory.',
                'os' => ['netapp'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/api/storage/volumes', 'capability' => 'storage', 'poll_interval' => 600],
                    ['path' => '/api/storage/aggregates', 'capability' => 'storage', 'poll_interval' => 900],
                    ['path' => '/api/cluster/nodes', 'capability' => 'inventory', 'poll_interval' => 900],
                    ['path' => '/api/storage/luns', 'capability' => 'storage', 'poll_interval' => 900],
                ],
            ],
            'cisco_ucsm' => [
                'name' => 'Cisco UCS Manager',
                'description' => 'Cisco UCS Manager XML API',
                'os' => ['cisco-ucsm', 'cisco-usm'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/nuova', 'capability' => 'inventory', 'method' => 'POST', 'poll_interval' => 600],
                    ['path' => '/nuova', 'capability' => 'sensors', 'method' => 'POST', 'poll_interval' => 300],
                ],
            ],
            'cisco_ftd' => [
                'name' => 'Cisco FTD',
                'description' => 'Cisco Firepower Threat Defense API',
                'os' => ['cisco-ftd'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/api/fdm/latest/devices/default/status', 'capability' => 'system', 'poll_interval' => 300],
                    ['path' => '/api/fdm/latest/devices/default/running/config/interfaces', 'capability' => 'ports', 'poll_interval' => 600],
                    ['path' => '/api/fdm/latest/devices/default/monitoring/interfaces', 'capability' => 'ports_stats', 'poll_interval' => 300],
                ],
            ],
            'velocloud' => [
                'name' => 'VMware VeloCloud',
                'description' => 'VeloCloud SD-WAN API',
                'os' => ['velocloud'],
                'auth_type' => 'basic',
                'base_url_pattern' => 'https://{hostname}',
                'endpoints' => [
                    ['path' => '/portal/rest/enterprise/getEnterprise', 'capability' => 'inventory', 'method' => 'POST', 'poll_interval' => 900],
                    ['path' => '/portal/rest/enterprise/getEnterpriseEdgeList', 'capability' => 'ports', 'method' => 'POST', 'poll_interval' => 600],
                    ['path' => '/portal/rest/monitoring/getEdgeLinkMetrics', 'capability' => 'sensors', 'method' => 'POST', 'poll_interval' => 300],
                    ['path' => '/portal/rest/monitoring/getAggregateEdgeLinkMetrics', 'capability' => 'metrics', 'method' => 'POST', 'poll_interval' => 300],
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
     */
    public static function getAuthTypes(): array
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
