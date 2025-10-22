<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomAttributeValues extends Model
{
    protected $table = 'customAttributeValues';
    protected $primaryKey = 'id';

    protected $fillable = [
        'device_id',
        'attribute_name',
        'attribute_value',
    ];

    protected $casts = [
        'attribute_value' => 'json',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
