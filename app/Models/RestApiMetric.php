<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiMetric extends Model
{
    protected $table = 'rest_api_metrics';

    protected $fillable = [
        'device_id',
        'endpoint_name',
        'resource_type',
        'metric_key',
        'metric_value',
        'last_updated',
    ];

    protected $casts = [
        'last_updated' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
