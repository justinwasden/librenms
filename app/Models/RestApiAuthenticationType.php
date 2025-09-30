<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiAuthenticationType extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function credentials()
    {
        return $this->hasMany(RestApiCredential::class, 'authentication_type_id');
    }
}