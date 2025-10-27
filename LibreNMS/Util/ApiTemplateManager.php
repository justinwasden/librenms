<?php

namespace LibreNMS\Util;

use App\Models\DeviceApiAuthSchema;
use App\Models\DeviceApiTemplate;

/**
 * Manages API templates for vendor device connections
 */
class ApiTemplateManager
{
    /**
     * Get all available templates
     *
     * @return array Array of template metadata
     */
    public static function getAllTemplates(): array
    {
        $templates = [];

        $dbTemplates = DeviceApiTemplate::with('schema')->enabled()->get();

        foreach ($dbTemplates as $template) {
            $templates[$template->key] = [
                'id' => $template->id,
                'vendor' => $template->key,
                'name' => $template->label,
                'description' => $template->description ?? '',
                'os' => $template->os_keys ?? [],
                'schema_id' => $template->schema_id,
                'capabilities' => $template->capabilities ?? [],
                'modules' => $template->modules ?? [],
            ];
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
     * Load a specific template by key
     *
     * @param string $key Template key
     * @return array|null
     */
    public static function loadTemplate(string $key): ?array
    {
        $template = DeviceApiTemplate::with(['schema.fields', 'endpoints'])
            ->where('key', $key)
            ->enabled()
            ->first();

        if (!$template) {
            return null;
        }

        return [
            'id' => $template->id,
            'vendor' => $template->key,
            'name' => $template->label,
            'description' => $template->description,
            'os' => $template->os_keys ?? [],
            'auth_type' => $template->schema->key ?? null,
            'schema_id' => $template->schema_id,
            'base_url_pattern' => $template->default_values['base_url_pattern'] ?? '',
            'capabilities' => $template->capabilities ?? [],
            'modules' => $template->modules ?? [],
            'endpoints' => $template->endpoints->map(function ($endpoint) {
                return [
                    'capability' => $endpoint->capability,
                    'method' => $endpoint->method,
                    'path' => $endpoint->path,
                    'transform' => $endpoint->transform,
                    'headers' => $endpoint->headers ?? [],
                    'request_body' => $endpoint->request_body ?? null,
                    'enabled' => $endpoint->enabled,
                ];
            })->toArray(),
        ];
    }

    /**
     * Get supported authentication types
     *
     * @return array
     */
    public static function getAuthTypes(): array
    {
        $authTypes = [];

        $schemas = DeviceApiAuthSchema::with('fields')->enabled()->get();

        foreach ($schemas as $schema) {
            $authTypes[$schema->key] = [
                'id' => $schema->id,
                'name' => $schema->label,
                'description' => $schema->description,
                'vendor' => $schema->vendor,
                'fields' => $schema->fields->map(function ($field) {
                    return [
                        'name' => $field->name,
                        'label' => $field->label,
                        'type' => $field->type,
                        'required' => $field->required,
                        'encrypted' => $field->encrypted,
                        'placeholder' => $field->placeholder,
                        'default' => $field->default,
                        'options' => $field->options,
                    ];
                })->toArray(),
            ];
        }

        return $authTypes;
    }

    /**
     * Get fields required for a specific auth type
     *
     * @param string $authType Auth schema key
     * @return array
     */
    public static function getAuthFields(string $authType): array
    {
        $schema = DeviceApiAuthSchema::with('fields')
            ->where('key', $authType)
            ->enabled()
            ->first();

        if (!$schema) {
            return [];
        }

        return $schema->fields->map(function ($field) {
            return [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'required' => $field->required,
                'encrypted' => $field->encrypted,
                'placeholder' => $field->placeholder,
                'default' => $field->default,
                'options' => $field->options,
            ];
        })->toArray();
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
