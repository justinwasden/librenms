<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageArrayVolume extends Model
{
    protected $table = 'storage_array_volumes';
    protected $primaryKey = 'volume_id';
    
    protected $fillable = [
        'device_id',
        'array_id',
        'name',
        'volume_type',
        'serial',
        'wwn',
        'pod_name',
        'pod_id',
        'volume_group',
        'volume_group_id',
        'total_provisioned',
        'used_provisioned',
        'total_physical',
        'total_used',
        'snapshots',
        'unique',
        'shared',
        'system',
        'data_reduction',
        'total_reduction',
        'data_reduction_percent',
        'thin_provisioning',
        'status',
        'provisioned',
        'host_count',
        'mapped_hosts',
        'qos_max_iops',
        'qos_max_bandwidth',
        'read_bandwidth',
        'write_bandwidth',
        'read_iops',
        'write_iops',
        'read_latency',
        'write_latency',
        'created_at_source',
        'last_polled',
    ];
    
    protected $casts = [
        'total_provisioned' => 'integer',
        'used_provisioned' => 'integer',
        'total_physical' => 'integer',
        'total_used' => 'integer',
        'snapshots' => 'integer',
        'unique' => 'integer',
        'shared' => 'integer',
        'system' => 'integer',
        'data_reduction' => 'decimal:2',
        'total_reduction' => 'decimal:2',
        'data_reduction_percent' => 'decimal:2',
        'thin_provisioning' => 'decimal:2',
        'host_count' => 'integer',
        'mapped_hosts' => 'array',
        'qos_max_iops' => 'integer',
        'qos_max_bandwidth' => 'integer',
        'read_bandwidth' => 'integer',
        'write_bandwidth' => 'integer',
        'read_iops' => 'integer',
        'write_iops' => 'integer',
        'read_latency' => 'decimal:3',
        'write_latency' => 'decimal:3',
        'created_at_source' => 'datetime',
        'last_polled' => 'datetime',
    ];
    
    /**
     * Get the device that owns this volume
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
    
    /**
     * Get the array that owns this volume
     */
    public function array(): BelongsTo
    {
        return $this->belongsTo(StorageArray::class, 'array_id', 'array_id');
    }
    
    /**
     * Calculate utilization percentage
     */
    public function getUtilizationAttribute(): float
    {
        if ($this->total_provisioned == 0) {
            return 0;
        }
        return round(($this->used_provisioned / $this->total_provisioned) * 100, 2);
    }
    
    /**
     * Calculate physical utilization percentage
     */
    public function getPhysicalUtilizationAttribute(): float
    {
        if ($this->total_physical == 0) {
            return 0;
        }
        return round(($this->total_used / $this->total_physical) * 100, 2);
    }
    
    /**
     * Format provisioned size for display
     */
    public function getFormattedProvisionedAttribute(): string
    {
        return $this->formatBytes($this->total_provisioned);
    }
    
    /**
     * Format used size for display
     */
    public function getFormattedUsedAttribute(): string
    {
        return $this->formatBytes($this->used_provisioned);
    }
    
    /**
     * Format physical size for display
     */
    public function getFormattedPhysicalAttribute(): string
    {
        return $this->formatBytes($this->total_physical);
    }
    
    /**
     * Check if volume is mapped to any hosts
     */
    public function isMapped(): bool
    {
        return $this->host_count > 0;
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
