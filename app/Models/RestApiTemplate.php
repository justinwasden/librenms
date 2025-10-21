<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestApiTemplate extends Model
{
    protected $fillable = [
        'name',
        'vendor',
        'description',
        'template_data',
    ];

    protected $casts = [
        'template_data' => 'array',
    ];

    public function endpoints(): HasMany
    {
        return $this->hasMany(RestApiEndpoint::class);
    }

    public function deviceTemplates(): HasMany
    {
        return $this->hasMany(RestApiDeviceTemplate::class);
    }

    public function devices()
    {
        return $this->hasManyThrough(
            Device::class,
            RestApiDeviceTemplate::class,
            'template_id',
            'device_id'
        );
    }
}
