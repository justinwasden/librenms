<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceApiAuthSchema extends Model
{
    use HasFactory;

    protected $table = 'device_api_auth_schemas';

    protected $fillable = [
        'key',
        'label',
        'description',
        'vendor',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get the fields for this auth schema
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DeviceApiAuthSchemaField::class, 'schema_id')->orderBy('display_order');
    }

    /**
     * Get templates that use this schema
     */
    public function templates(): HasMany
    {
        return $this->hasMany(DeviceApiTemplate::class, 'schema_id');
    }

    /**
     * Get configs that use this schema
     */
    public function configs(): HasMany
    {
        return $this->hasMany(DeviceApiConfig::class, 'schema_id');
    }

    /**
     * Scope to only enabled schemas
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by vendor
     */
    public function scopeForVendor($query, $vendor)
    {
        return $query->where('vendor', $vendor);
    }
}
