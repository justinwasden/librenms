<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageArray extends Model
{
    protected $table = 'storage_arrays';
    protected $primaryKey = 'array_id';
    
    protected $fillable = [
        'device_id',
        'name',
        'array_type',
        'model',
        'version',
        'serial_number',
        'total_capacity',
        'total_physical',
        'total_used',
        'total_free',
        'total_provisioned',
        'snapshots',
        'system',
        'data_reduction',
        'total_reduction',
        'thin_provisioning',
        'shared_space',
        'deduplication',
        'compression',
        'status',
        'health',
        'read_bandwidth',
        'write_bandwidth',
        'read_iops',
        'write_iops',
        'read_latency',
        'write_latency',
        'last_polled',
    ];
    
    protected $casts = [
        'total_capacity' => 'integer',
        'total_physical' => 'integer',
        'total_used' => 'integer',
        'total_free' => 'integer',
        'total_provisioned' => 'integer',
        'snapshots' => 'integer',
        'system' => 'integer',
        'data_reduction' => 'decimal:2',
        'total_reduction' => 'decimal:2',
        'thin_provisioning' => 'decimal:2',
        'shared_space' => 'decimal:2',
        'deduplication' => 'decimal:2',
        'compression' => 'decimal:2',
        'read_bandwidth' => 'integer',
        'write_bandwidth' => 'integer',
        'read_iops' => 'integer',
        'write_iops' => 'integer',
        'read_latency' => 'decimal:3',
        'write_latency' => 'decimal:3',
        'last_polled' => 'datetime',
    ];
    
    /**
     * Get the device that owns this storage array
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
    
    /**
     * Get the controllers for this array
     */
    public function controllers(): HasMany
    {
        return $this->hasMany(StorageController::class, 'array_id', 'array_id');
    }
    
    /**
     * Get the volumes for this array
     */
    public function volumes(): HasMany
    {
        return $this->hasMany(StorageArrayVolume::class, 'array_id', 'array_id');
    }
    
    /**
     * Get the hosts for this array
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(StorageArrayHost::class, 'array_id', 'array_id');
    }
    
    /**
     * Calculate utilization percentage
     */
    public function getUtilizationAttribute(): float
    {
        if ($this->total_capacity == 0) {
            return 0;
        }
        return round(($this->total_used / $this->total_capacity) * 100, 2);
    }
    
    /**
     * Format capacity for display
     */
    public function getFormattedCapacityAttribute(): string
    {
        return $this->formatBytes($this->total_capacity);
    }
    
    /**
     * Format used space for display
     */
    public function getFormattedUsedAttribute(): string
    {
        return $this->formatBytes($this->total_used);
    }
    
    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
