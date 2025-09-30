<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiEndpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'name',
        'path',
        'method',
        'query_params',
        'headers',
        'body',
        'metric_map',
        'last_polled',
    ];

    protected $casts = [
        'query_params' => 'json',
        'headers' => 'json',
        'body' => 'json',
        'metric_map' => 'json',
        'last_polled' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(RestApiConnection::class, 'connection_id');
    }

    public function metrics()
    {
        return $this->hasMany(RestApiMetric::class, 'endpoint_id');
    }
}