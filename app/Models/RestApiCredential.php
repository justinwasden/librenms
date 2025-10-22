<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestApiCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'authentication_type_id',
        'notes',
    ];

    protected $hidden = [];

    protected $casts = [];

    /**
     * Get the authentication type this credential uses
     */
    public function authenticationType(): BelongsTo
    {
        return $this->belongsTo(RestApiAuthenticationType::class, 'authentication_type_id');
    }

    /**
     * Get all parameters for this credential
     */
    public function params(): HasMany
    {
        return $this->hasMany(RestApiCredentialParam::class, 'credential_id');
    }

    /**
     * Get a specific parameter value by key
     */
    public function getParamValue(string $key, $default = null)
    {
        return $this->params()
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /**
     * Get all parameters as key => value array
     * Note: This properly decrypts values using the model's getAttribute accessor
     */
    public function getParamsArray(): array
    {
        $result = [];
        foreach ($this->params as $param) {
            $result[$param->key] = $param->value; // This uses getAttribute() which decrypts
        }
        return $result;
    }
}
