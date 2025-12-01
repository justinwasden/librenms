<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceApiEndpoint extends Model
{
    use HasFactory;

    protected $table = 'device_api_endpoints';

    protected $fillable = [
        'device_id',
        'template_endpoint_id',
        'name',
        'path',
        'method',
        'capability',
        'poll_interval',
        'enabled',
        'transform',
        'headers',
        'request_body',
        'display_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'headers' => 'array',
        'request_body' => 'array',
        'poll_interval' => 'integer',
        'display_order' => 'integer',
    ];

    /**
     * Get the device this endpoint belongs to
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Scope to only get enabled endpoints
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to order by display_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('id');
    }
}
