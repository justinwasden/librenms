<?php

namespace LibreNMS\Util;

use Illuminate\Support\Facades\File;

/**
 * Manages API templates for vendor device connections
 */
class ApiTemplateManager
{
    protected static string $templatePath = 'config/api-templates';

    /**
     * Get all available templates
     *
     * @return array Array of template metadata
     */
    public static function getAllTemplates(): array
    {
        $templates = [];
        $path = base_path(self::$templatePath);

        if (!File::isDirectory($path)) {
            return [];
        }

        $files = File::glob($path . '/*.json');

        foreach ($files as $file) {
            $content = File::get($file);
            $template = json_decode($content, true);

            if ($template && isset($template['vendor'])) {
                $templates[$template['vendor']] = [
                    'vendor' => $template['vendor'],
                    'name' => $template['name'],
                    'description' => $template['description'] ?? '',
                    'os' => $template['os'] ?? [],
                ];
            }
        }

        return $templates;
    }

    /**
     * Get templates filtered by device OS
     *
     * @param string $os Device OS
     * @return array Array of template metadata matching the OS
     */
    public static function getTemplatesForOs(string $os): array
    {
        $allTemplates = self::getAllTemplates();
        $osSpecific = [];
        $generic = [];

        foreach ($allTemplates as $vendor => $template) {
            if (empty($template['os'])) {
                // Generic template (no OS specified)
                $generic[$vendor] = $template;
            } elseif (in_array($os, $template['os'])) {
                // OS-specific template
                $osSpecific[$vendor] = $template;
            }
        }

        // Return OS-specific templates if available, otherwise return generic templates
        return !empty($osSpecific) ? $osSpecific : $generic;
    }

    /**
     * Load a specific template by vendor
     *
     * @param string $vendor
     * @return array|null
     */
    public static function loadTemplate(string $vendor): ?array
    {
        $filePath = base_path(self::$templatePath . '/' . $vendor . '.json');

        if (!File::exists($filePath)) {
            return null;
        }

        $content = File::get($filePath);
        $template = json_decode($content, true);

        return $template ?: null;
    }

    /**
     * Get supported authentication types
     *
     * @return array
     */
    public static function getAuthTypes(): array
    {
        return [
            'bearer' => [
                'name' => 'Bearer Token',
                'fields' => ['token'],
                'description' => 'Authorization: Bearer {token}',
            ],
            'apikey' => [
                'name' => 'API Key / Token',
                'fields' => ['token'],
                'description' => 'X-API-Key: {token}',
            ],
            'basic' => [
                'name' => 'Basic Authentication',
                'fields' => ['username', 'password'],
                'description' => 'Authorization: Basic base64(username:password)',
            ],
            'token' => [
                'name' => 'Proxmox API Token',
                'fields' => ['proxmox_token_user', 'proxmox_token_id', 'proxmox_token'],
                'description' => 'PVEAPIToken=user@realm!tokenid=secret',
            ],
            'ticket' => [
                'name' => 'Proxmox Ticket (Username/Password)',
                'fields' => ['proxmox_username', 'proxmox_password'],
                'description' => 'Username/password authentication with cookie session',
            ],
        ];
    }

    /**
     * Get fields required for a specific auth type
     *
     * @param string $authType
     * @return array
     */
    public static function getAuthFields(string $authType): array
    {
        $authTypes = self::getAuthTypes();
        return $authTypes[$authType]['fields'] ?? [];
    }

    /**
     * Validate template structure
     *
     * @param array $template
     * @return bool
     */
    public static function validateTemplate(array $template): bool
    {
        $required = ['name', 'vendor', 'auth_type', 'endpoints'];

        foreach ($required as $field) {
            if (!isset($template[$field])) {
                return false;
            }
        }

        return true;
    }
}
