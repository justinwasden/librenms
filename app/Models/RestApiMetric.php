<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiMetric extends Model
{
    // Ensure this matches your actual table name if different
    protected $table = 'rest_api_metrics';

    /**
     * The attributes that are mass assignable.
     * These must be explicitly allowed for the batch insert in Api.php to succeed.
     */
    protected $fillable = [
        'endpoint_id',
        'metric_name',
        'metric_value',
        'collected_at',
    ];

    /**
     * Ensure JSON column storage is correctly cast.
     */
    protected $casts = [
        'collected_at' => 'datetime',
    ];

    /**
     * Define relationship to the endpoint that collected this metric.
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(RestApiEndpoint::class, 'endpoint_id');
    }
}