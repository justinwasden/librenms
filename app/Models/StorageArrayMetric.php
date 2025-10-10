<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageArrayMetric extends Model
{
    protected $table = 'storage_array_metrics';
    
    public $timestamps = false; // We use last_updated instead

    protected $fillable = [
        'device_id',
        'metric_type',
        'metric_name',
        'metric_value',
        'last_updated',
    ];

    protected $casts = [
        'metric_value' => 'array', // Automatically cast JSON to/from array
        'last_updated' => 'datetime',
    ];

    /**
     * Get the device this metric belongs to
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Store or update a metric
     */
    public static function storeMetric(int $deviceId, string $metricType, string $metricName, array $metricValue): self
    {
        return self::updateOrCreate(
            [
                'device_id' => $deviceId,
                'metric_type' => $metricType,
                'metric_name' => $metricName,
            ],
            [
                'metric_value' => $metricValue,
                'last_updated' => now(),
            ]
        );
    }

    /**
     * Get metrics by type for a device
     */
    public static function getMetricsByType(int $deviceId, string $metricType): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('device_id', $deviceId)
                   ->where('metric_type', $metricType)
                   ->get();
    }
}
