<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestApiEndpoint extends Model
{
    protected $fillable = [
        'template_id',
        'name',
        'path',
        'http_method',
        'poll_interval',
        'resource_type',
        'template_response_mapping',
    ];

    protected $casts = [
        'template_response_mapping' => 'array',
        'poll_interval' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(RestApiTemplate::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(RestApiMapping::class);
    }

    public function getMappingConfig(): array
    {
        return $this->template_response_mapping ?? [];
    }

    public function getUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . $this->path;
    }
}
