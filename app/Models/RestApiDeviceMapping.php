<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiDeviceMapping extends Model
{
    protected $fillable = [
        'device_id',
        'endpoint_id',
        'mapping_type',
        'mapping_name',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(RestApiEndpoint::class);
    }
}
