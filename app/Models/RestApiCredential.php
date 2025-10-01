<?php

namespace App\Models;

use App\Models\Traits\Encryptable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiCredential extends Model
{
    use HasFactory, Encryptable;

    protected $fillable = ['name', 'authentication_type_id'];

    public function authenticationType()
    {
        return $this->belongsTo(RestApiAuthenticationType::class, 'authentication_type_id');
    }

    public function params()
    {
        return $this->hasMany(RestApiCredentialParam::class, 'credential_id');
    }

    public function connections()
    {
        return $this->hasMany(RestApiConnection::class, 'credential_id');
    }
}