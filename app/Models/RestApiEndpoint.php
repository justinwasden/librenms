<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestApiEndpoint extends Model
{
    protected $fillable = [
        'template_id',
        'connection_id',
        'name',
        'path',
        'http_method',
        'method',
        'poll_interval',
        'resource_type',
        'template_response_mapping',
        'metric_map',
        'enabled',
    ];

    protected $casts = [
        'template_response_mapping' => 'array',
        'metric_map' => 'array',
        'poll_interval' => 'integer',
        'enabled' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(RestApiTemplate::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(RestApiConnection::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(RestApiMapping::class);
    }

    public function getMappingConfig(): array
    {
        return $this->template_response_mapping ?? $this->metric_map ?? [];
    }

    public function getUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . $this->path;
    }
}
