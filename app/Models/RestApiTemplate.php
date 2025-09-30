<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'vendor', 'template_data'];

    protected $casts = [
        'template_data' => 'json',
    ];
}