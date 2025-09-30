<?php

namespace App\Models;

use App\Models\Traits\Encryptable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiCredentialParam extends Model
{
    use HasFactory, Encryptable;

    protected $fillable = ['credential_id', 'key', 'value'];

    protected $encryptable = ['value'];

    public function credential()
    {
        return $this->belongsTo(RestApiCredential::class, 'credential_id');
    }
}