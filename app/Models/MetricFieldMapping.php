<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'metric_name',
        'resource_type',
        'vendor',
        'os',
        'librenms_table',
        'librenms_field',
        'data_type',
        'unit',
        'multiplier',
        'last_matched_device_id',
        'last_seen_at',
        'auto_learned',
        'enabled',
        'description',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'auto_learned' => 'boolean',
        'enabled' => 'boolean',
        'multiplier' => 'decimal:4',
    ];

    public function lastMatchedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'last_matched_device_id', 'device_id');
    }

    public function scopeForMetric($query, string $metricName, ?string $resourceType = null)
    {
        return $query->where('metric_name', strtolower($metricName))
            ->where(function ($q) use ($resourceType) {
                $q->where('resource_type', strtolower($resourceType))
                  ->orWhereNull('resource_type');
            })
            ->where('enabled', true);
    }

    public function scopeForDevice($query, Device $device)
    {
        return $query->where(function ($q) use ($device) {
            $q->where('vendor', $device->vendor)
              ->orWhereNull('vendor');
        })
        ->where(function ($q) use ($device) {
            $q->where('os', $device->os)
              ->orWhereNull('os');
        })
        ->orderByRaw('vendor IS NULL ASC, os IS NULL ASC'); // Prefer specific over generic
    }

    public function scopeAutoLearned($query)
    {
        return $query->where('auto_learned', true);
    }

    public function scopeManual($query)
    {
        return $query->where('auto_learned', false);
    }

    public function scopeUnmatched($query)
    {
        return $query->where('librenms_table', 'unmatched')
            ->orWhere('librenms_field', 'unmatched');
    }

    public function isUnmatched(): bool
    {
        return !$this->enabled || empty($this->librenms_table) || empty($this->librenms_field);
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = [$this->metric_name];

        if ($this->resource_type) {
            $parts[] = "({$this->resource_type})";
        }

        if ($this->vendor || $this->os) {
            $context = [];
            if ($this->vendor) $context[] = $this->vendor;
            if ($this->os) $context[] = $this->os;
            $parts[] = '[' . implode('/', $context) . ']';
        }

        return implode(' ', $parts);
    }

    public function getTargetAttribute(): string
    {
        if ($this->isUnmatched()) {
            return 'Unmatched';
        }

        return "{$this->librenms_table}.{$this->librenms_field}";
    }

    public function transformValue($value)
    {
        if ($value === null) {
            return null;
        }

        // Handle unit conversions for numeric values
        if ($this->data_type === 'numeric' && is_numeric($value)) {
            $value = $this->convertUnits($value);
            
            // Apply multiplier if set and reasonable
            if ($this->multiplier && $this->multiplier != 1.0) {
                $value = $value * $this->multiplier;
            }
        }

        // Type casting
        switch ($this->data_type) {
            case 'numeric':
                return is_numeric($value) ? (float)$value : null;
            case 'boolean':
                return (bool)$value;
            case 'string':
                return (string)$value;
            case 'json':
                return is_string($value) ? $value : json_encode($value);
            default:
                return $value;
        }
    }
    
    /**
     * Convert units if needed
     */
    protected function convertUnits($value)
    {
        if (!$this->unit) {
            return $value;
        }
        
        // Speed conversions to bps (bits per second)
        if ($this->librenms_field === 'ifSpeed') {
            switch (strtolower($this->unit)) {
                case 'gbps':
                    return $value * 1000000000; // Gbps to bps
                case 'mbps':
                    return $value * 1000000; // Mbps to bps
                case 'kbps':
                    return $value * 1000; // Kbps to bps
            }
        }
        
        // Storage conversions to bytes
        if (in_array($this->librenms_field, ['storage_size', 'storage_used', 'storage_free', 'mempool_used', 'mempool_free'])) {
            switch (strtolower($this->unit)) {
                case 'tb':
                case 'tib':
                    return $value * 1099511627776; // TB to bytes
                case 'gb':
                case 'gib':
                    return $value * 1073741824; // GB to bytes
                case 'mb':
                case 'mib':
                    return $value * 1048576; // MB to bytes
                case 'kb':
                case 'kib':
                    return $value * 1024; // KB to bytes
            }
        }
        
        return $value;
    }
}
