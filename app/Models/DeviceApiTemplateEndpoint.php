<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceApiTemplateEndpoint extends Model
{
    use HasFactory;

    protected $table = 'device_api_template_endpoints';

    protected $fillable = [
        'template_id',
        'capability',
        'method',
        'path',
        'request_body',
        'headers',
        'rate_limit_qps',
        'enabled',
        'transform',
        'display_order',
    ];

    protected $casts = [
        'request_body' => 'array',
        'headers' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Get the template this endpoint belongs to
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DeviceApiTemplate::class, 'template_id');
    }

    /**
     * Scope to only enabled endpoints
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by capability
     */
    public function scopeForCapability($query, $capability)
    {
        return $query->where('capability', $capability);
    }
}
