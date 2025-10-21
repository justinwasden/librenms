<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RestApiMapping - Maps REST API response fields to LibreNMS database fields
 * 
 * Each mapping defines:
 * - Which field in the API response (JSONPath)
 * - Where to store it (table + field)
 * - Optional data transformation
 */
class RestApiMapping extends Model
{
    protected $table = 'rest_api_mappings';

    protected $fillable = [
        'endpoint_id',
        'target_table',
        'target_field',
        'source_field',
        'data_type',
        'is_identifier',
        'is_required',
        'transformation',
        'enabled',
    ];

    protected $casts = [
        'is_identifier' => 'boolean',
        'is_required' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function endpoint()
    {
        return $this->belongsTo(RestApiEndpoint::class);
    }
}
