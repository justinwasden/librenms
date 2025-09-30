<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'credential_id',
        'name',
        'base_url',
        'rate_limit',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function credential()
    {
        return $this->belongsTo(RestApiCredential::class, 'credential_id');
    }

    public function endpoints()
    {
        return $this->hasMany(RestApiEndpoint::class, 'connection_id');
    }
}