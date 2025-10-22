<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiCredentialParam extends Model
{
    protected $fillable = [
        'credential_id',
        'key',
        'value',
    ];

    protected $hidden = ['value']; // Hide sensitive values from API responses

    public function credential(): BelongsTo
    {
        return $this->belongsTo(RestApiCredential::class);
    }
}
