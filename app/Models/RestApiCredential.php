<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiCredential extends Model
{
    protected $fillable = [
        'device_id',
        'auth_type',
        'username',
        'password',
        'auth_token',
        'extra_data',
    ];

    protected $hidden = [
        'password',
        'auth_token',
    ];

    protected $casts = [
        'extra_data' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function getAuthHeader(): array
    {
        return match($this->auth_type) {
            'bearer_token' => ['Authorization' => "Bearer {$this->auth_token}"],
            'api_token' => ['Authorization' => "Bearer {$this->auth_token}"],
            'oauth2' => ['Authorization' => "Bearer {$this->auth_token}"],
            'basic_auth' => ['Authorization' => 'Basic ' . base64_encode("{$this->username}:{$this->password}")],
            default => [],
        };
    }
}
