<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiAuthSchema extends Model
{
    protected $table = 'api_auth_schemas';

    protected $fillable = [
        'key',
        'name',
        'description',
        'fields',
        'is_system',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Get templates using this auth schema
     */
    public function templates(): HasMany
    {
        return $this->hasMany(ApiTemplate::class, 'auth_type', 'key');
    }

    /**
     * Get the field definitions with defaults applied
     */
    public function getFieldsWithDefaults(): array
    {
        $fields = $this->fields ?? [];
        $result = [];

        foreach ($fields as $field) {
            $result[] = [
                'name' => $field['name'] ?? '',
                'label' => $field['label'] ?? ucfirst($field['name'] ?? ''),
                'type' => $field['type'] ?? 'text',
                'required' => $field['required'] ?? false,
                'encrypted' => $field['encrypted'] ?? false,
                'placeholder' => $field['placeholder'] ?? '',
                'default' => $field['default'] ?? null,
            ];
        }

        return $result;
    }
}
