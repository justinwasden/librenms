<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceApiTemplate extends Model
{
    use HasFactory;

    protected $table = 'device_api_templates';

    protected $fillable = [
        'key',
        'label',
        'os_keys',
        'schema_id',
        'default_values',
        'modules',
        'capabilities',
        'description',
        'enabled',
    ];

    protected $casts = [
        'os_keys' => 'array',
        'default_values' => 'array',
        'modules' => 'array',
        'capabilities' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Get the auth schema for this template
     */
    public function schema(): BelongsTo
    {
        return $this->belongsTo(DeviceApiAuthSchema::class, 'schema_id');
    }

    /**
     * Get the endpoints for this template
     */
    public function endpoints(): HasMany
    {
        return $this->hasMany(DeviceApiTemplateEndpoint::class, 'template_id')->orderBy('display_order');
    }

    /**
     * Get configs using this template
     */
    public function configs(): HasMany
    {
        return $this->hasMany(DeviceApiConfig::class, 'template_id');
    }

    /**
     * Scope to only enabled templates
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by OS
     */
    public function scopeForOs($query, $os)
    {
        return $query->whereJsonContains('os_keys', $os)
            ->orWhereJsonLength('os_keys', 0); // Include templates with empty os_keys (generic)
    }

    /**
     * Check if this template supports a given OS
     */
    public function supportsOs(string $os): bool
    {
        $osKeys = $this->os_keys ?? [];

        // Empty os_keys means generic template
        if (empty($osKeys)) {
            return true;
        }

        return in_array($os, $osKeys);
    }

    /**
     * Check if this template has a specific capability
     */
    public function hasCapability(string $capability): bool
    {
        $capabilities = $this->capabilities ?? [];
        return in_array($capability, $capabilities);
    }
}
