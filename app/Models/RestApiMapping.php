<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestApiMapping extends Model
{
    protected $fillable = [
        'endpoint_id',
        'source_field',
        'target_field',
        'target_table',
        'data_type',
        'is_identifier',
        'is_required',
        'transform_logic',
    ];

    protected $casts = [
        'is_identifier' => 'boolean',
        'is_required' => 'boolean',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(RestApiEndpoint::class);
    }
}
