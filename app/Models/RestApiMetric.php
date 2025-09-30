<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'endpoint_id',
        'metric_name',
        'metric_value',
        'collected_at',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
    ];

    public function endpoint()
    {
        return $this->belongsTo(RestApiEndpoint::class, 'endpoint_id');
    }
}