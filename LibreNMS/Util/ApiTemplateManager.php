<?php

namespace LibreNMS\Util;

use App\Models\DeviceApiAuthSchema;
use App\Models\DeviceApiTemplate;

/**
 * Manages API templates for vendor device connections
 */
class ApiTemplateManager
{
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

    public static function getTemplatesForOs(string $os): array
    {
        $allTemplates = self::getAllTemplates();
        $osSpecific = [];
        $generic = [];

        foreach ($allTemplates as $vendor => $template) {
            if (empty($template['os'])) {
                $generic[$vendor] = $template;
            } elseif (in_array($os, $template['os'])) {
                $osSpecific[$vendor] = $template;
            }
        }

        return !empty($osSpecific) ? $osSpecific : $generic;
    }

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