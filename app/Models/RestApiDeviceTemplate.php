<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiDeviceTemplate extends Model
{
    protected $fillable = [
        'device_id',
        'template_id',
        'mapper_name',
        'custom_mappings',
        'custom_mapping_name',
        'mapper_source',
    ];

    protected $casts = [
        'custom_mappings' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RestApiTemplate::class);
    }
}
