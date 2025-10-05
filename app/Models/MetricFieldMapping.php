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

    /**
     * Get the last device that matched this mapping
     */
    public function lastMatchedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'last_matched_device_id', 'device_id');
    }

    /**
     * Scope to get mappings for a specific metric
     */
    public function scopeForMetric($query, string $metricName, ?string $resourceType = null)
    {
        return $query->where('metric_name', strtolower($metricName))
            ->where(function ($q) use ($resourceType) {
                $q->where('resource_type', strtolower($resourceType))
                  ->orWhereNull('resource_type');
            })
            ->where('enabled', true);
    }

    /**
     * Scope to get mappings for a specific device context
     */
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

    /**
     * Scope to get only auto-learned mappings
     */
    public function scopeAutoLearned($query)
    {
        return $query->where('auto_learned', true);
    }

    /**
     * Scope to get only manually created mappings
     */
    public function scopeManual($query)
    {
        return $query->where('auto_learned', false);
    }

    /**
     * Scope to get unmatched placeholder mappings
     */
    public function scopeUnmatched($query)
    {
        return $query->where('librenms_table', 'unmatched')
            ->orWhere('librenms_field', 'unmatched');
    }

    /**
     * Check if this is an unmatched placeholder
     */
    public function isUnmatched(): bool
    {
        return $this->librenms_table === 'unmatched' || $this->librenms_field === 'unmatched';
    }

    /**
     * Get a human-readable display name
     */
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

    /**
     * Get the target field description
     */
    public function getTargetAttribute(): string
    {
        if ($this->isUnmatched()) {
            return 'Unmatched';
        }
        
        return "{$this->librenms_table}.{$this->librenms_field}";
    }

    /**
     * Apply value transformation based on multiplier and data type
     */
    public function transformValue($value)
    {
        if ($value === null) {
            return null;
        }

        // Apply multiplier for numeric values
        if ($this->data_type === 'numeric' && is_numeric($value)) {
            $value = $value * $this->multiplier;
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
}
