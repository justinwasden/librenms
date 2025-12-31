<?php

namespace LibreNMS\Util;

/**
 * Manages API templates for vendor device connections
 * Now uses hardcoded templates instead of database tables
 */
class ApiTemplateManager
{
    /**
     * Get all available API templates
     */
    public static function getAllTemplates(): array
    {
        return [
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
            'fortigate' => [
                'name' => 'Fortinet FortiGate',
                'description' => 'FortiGate REST API',
                'os' => ['fortigate'],
                'auth_type' => 'token',
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
            'netapp' => [
                'name' => 'NetApp ONTAP',
                'description' => 'NetApp ONTAP REST API',
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
                'os' => ['cisco-ucsm'],
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
            if (in_array($os, $template['os'])) {
                $matched[$vendor] = $template;
            }
        }

        return $matched;
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
            'token' => [
                'name' => 'API Token',
                'description' => 'Token-based authentication',
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
                        'name' => 'api_credential_token_url',
                        'label' => 'Token URL',
                        'type' => 'text',
                        'required' => true,
                        'encrypted' => false,
                        'placeholder' => 'https://auth.example.com/oauth/token',
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
}
