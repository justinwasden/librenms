<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\Encryptable; // <-- ADD THIS USE STATEMENT

class RestApiCredentialParam extends Model
{
    use Encryptable; // <-- ADD THIS TRAIT USE

    // ADD THIS ARRAY to tell the Encryptable trait which fields to protect
    protected array $encryptable = [
        'value',
    ];

    protected $fillable = [
        'credential_id',
        'key',
        'value',
    ];

    // NOTE: If you keep 'value' in $hidden, it will still be hidden
    // but the trait will now handle encryption/decryption when accessed directly.
    protected $hidden = ['value'];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(RestApiCredential::class);
    }
}