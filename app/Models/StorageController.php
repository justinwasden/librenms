<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageController extends Model
{
    protected $table = 'storage_controllers';
    protected $primaryKey = 'controller_id';
    
    protected $fillable = [
        'device_id',
        'array_id',
        'name',
        'controller_type',
        'model',
        'version',
        'serial_number',
        'status',
        'mode',
        'health',
        'hardware_version',
        'bios_version',
        'last_polled',
    ];
    
    protected $casts = [
        'last_polled' => 'datetime',
    ];
    
    /**
     * Get the device that owns this controller
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
    
    /**
     * Get the array that owns this controller
     */
    public function array(): BelongsTo
    {
        return $this->belongsTo(StorageArray::class, 'array_id', 'array_id');
    }
}
