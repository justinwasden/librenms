<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageArrayHost extends Model
{
    protected $table = 'storage_array_hosts';
    protected $primaryKey = 'host_id';
    
    protected $fillable = [
        'device_id',
        'array_id',
        'name',
        'host_type',
        'iqns',
        'wwns',
        'nqns',
        'port_connectivity_status',
        'port_connectivity_details',
        'connection_count',
        'connected_ports',
        'host_group',
        'host_group_id',
        'personality',
        'preferred_arrays',
        'volume_count',
        'mapped_volumes',
        'last_seen',
        'last_polled',
    ];
    
    protected $casts = [
        'iqns' => 'array',
        'wwns' => 'array',
        'nqns' => 'array',
        'port_connectivity_details' => 'array',
        'connected_ports' => 'array',
        'mapped_volumes' => 'array',
        'connection_count' => 'integer',
        'volume_count' => 'integer',
        'last_seen' => 'datetime',
        'last_polled' => 'datetime',
    ];
    
    /**
     * Get the device that owns this host
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
    
    /**
     * Get the array that owns this host
     */
    public function array(): BelongsTo
    {
        return $this->belongsTo(StorageArray::class, 'array_id', 'array_id');
    }
    
    /**
     * Check if host is connected
     */
    public function isConnected(): bool
    {
        return $this->port_connectivity_status === 'connected';
    }
    
    /**
     * Get all initiators (IQNs, WWNs, NQNs combined)
     */
    public function getAllInitiators(): array
    {
        return array_merge(
            $this->iqns ?? [],
            $this->wwns ?? [],
            $this->nqns ?? []
        );
    }
}
