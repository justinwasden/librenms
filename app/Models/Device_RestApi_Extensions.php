<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    // Existing relationships...

    public function restApiCredentials(): HasMany
    {
        return $this->hasMany(RestApiCredential::class);
    }

    public function restApiDeviceTemplate()
    {
        return $this->hasOne(RestApiDeviceTemplate::class);
    }

    public function restApiTemplate()
    {
        return $this->hasOneThrough(
            RestApiTemplate::class,
            RestApiDeviceTemplate::class,
            'device_id',
            'id',
            'device_id',
            'template_id'
        );
    }
}
