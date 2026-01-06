<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiTemplateEndpoint extends Model
{
    protected $table = 'api_template_endpoints';

    protected $fillable = [
        'template_id',
        'capability',
        'method',
        'path',
        'transform',
        'for_each',
        'body',
        'headers',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'body' => 'array',
        'headers' => 'array',
        'enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the template this endpoint belongs to
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ApiTemplate::class, 'template_id');
    }

    /**
     * Get endpoint as array for API consumption
     */
    public function toEndpointArray(): array
    {
        return [
            'capability' => $this->capability,
            'method' => $this->method,
            'path' => $this->path,
            'transform' => $this->transform,
            'for_each' => $this->for_each,
            'body' => $this->body,
            'headers' => $this->headers,
        ];
    }
}
