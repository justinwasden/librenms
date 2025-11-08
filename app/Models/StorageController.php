<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageController extends Model
{
    protected $table = 'storage_controllers';

    protected $fillable = [
        'device_id',
        'controller_name',
        'model',
        'status',
        'mode',
        'version',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
