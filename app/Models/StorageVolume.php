<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageVolume extends Model
{
    protected $table = 'storage_volumes';

    protected $fillable = [
        'device_id',
        'volume_name',
        'volume_id',
        'read_bandwidth',
        'write_bandwidth',
        'read_iops',
        'write_iops',
        'read_latency',
        'write_latency',
        'size_bytes',
        'used_bytes',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
