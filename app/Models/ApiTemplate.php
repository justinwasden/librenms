<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiTemplate extends Model
{
    protected $table = 'api_templates';

    protected $fillable = [
        'key',
        'name',
        'description',
        'os_types',
        'auth_type',
        'base_url_pattern',
        'capabilities',
        'is_system',
        'enabled',
    ];

    protected $casts = [
        'os_types' => 'array',
        'capabilities' => 'array',
        'is_system' => 'boolean',
        'enabled' => 'boolean',
    ];

    /**
     * Get the endpoints for this template
     */
    public function endpoints(): HasMany
    {
        return $this->hasMany(ApiTemplateEndpoint::class, 'template_id')->orderBy('sort_order');
    }

    /**
     * Get enabled endpoints only
     */
    public function enabledEndpoints(): HasMany
    {
        return $this->endpoints()->where('enabled', true);
    }

    /**
     * Get the auth schema for this template
     */
    public function authSchema(): BelongsTo
    {
        return $this->belongsTo(ApiAuthSchema::class, 'auth_type', 'key');
    }

    /**
     * Check if this template applies to a given OS
     */
    public function appliesToOs(string $os): bool
    {
        $osTypes = $this->os_types ?? [];
        return empty($osTypes) || in_array($os, $osTypes);
    }

    /**
     * Get template as array for API consumption
     */
    public function toTemplateArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'os' => $this->os_types ?? [],
            'auth_type' => $this->auth_type,
            'base_url_pattern' => $this->base_url_pattern,
            'capabilities' => $this->capabilities ?? [],
            'endpoints' => $this->enabledEndpoints->map(fn($ep) => $ep->toEndpointArray())->toArray(),
            'is_system' => $this->is_system,
        ];
    }
}
