<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestApiTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'vendor', 'template_data'];

    protected $casts = [
        'template_data' => 'array',
    ];

    /**
     * Scope to filter templates applicable to a specific device
     */
    public function scopeForDevice($query, Device $device)
    {
        return $query->where(function ($q) use ($device) {
            $q->where('vendor', $device->hardware)
              ->orWhere('vendor', $device->os)
              ->orWhere('vendor', 'LIKE', '%' . $device->hardware . '%')
              ->orWhere('vendor', 'LIKE', '%' . $device->os . '%')
              ->orWhereNull('vendor')  // generic templates
              ->orWhere('vendor', '');
        });
    }
}