<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageHost extends Model
{
    protected $table = 'storage_hosts';

    protected $fillable = [
        'device_id',
        'host_name',
        'personality',
        'host_group',
        'is_local',
        'port_connectivity_status',
        'port_connectivity_details',
        'iqn',
        'wwns',
    ];

    protected $casts = [
        'is_local' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
