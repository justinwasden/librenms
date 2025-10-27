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