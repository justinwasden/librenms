<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestApiMetricFieldMapping extends Model
{
    protected $table = 'rest_api_metric_field_mappings';

    protected $fillable = [
        'device_id',
        'api_field_name',
        'librenms_table',
        'librenms_field',
        'unit',
        'transform',
        'confidence_score',
        'enabled',
        'user_created',
        'last_matched_device_id',
        'last_seen_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'user_created' => 'boolean',
        'confidence_score' => 'decimal:2',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Scope to filter by device (null = global)
     */
    public function scopeForDevice($query, Device $device)
    {
        return $query->where(function ($q) use ($device) {
            $q->whereNull('device_id')
              ->orWhere('device_id', $device->device_id);
        });
    }

    /**
     * Get device relationship
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Transform the value according to the mapping
     */
    public function transformValue($value)
    {
        if (!$this->transform) {
            return $value;
        }

        // Apply transformation if specified
        switch ($this->transform) {
            case 'bytes_to_bits':
                return $value * 8;
            case 'bits_to_bytes':
                return $value / 8;
            case 'percent_to_decimal':
                return $value / 100;
            case 'decimal_to_percent':
                return $value * 100;
            case 'boolean_to_int':
                return $value ? 1 : 0;
            case 'boolean_to_updown':
                return $value ? 'up' : 'down';
            case 'string_to_lower':
                return strtolower($value);
            case 'string_to_upper':
                return strtoupper($value);
            default:
                return $value;
        }
    }
}
