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

    /**
     * Constructs the full URL by combining the Base URL and the Endpoint path (which may be resolved).
     *
     * @param string $baseUrl The connection's base URL (already processed for placeholders and port).
     * @param string|null $resolvedPath The endpoint path, potentially after placeholder replacement.
     * @return string The fully qualified URL.
     */
    public function getUrl(string $baseUrl, ?string $resolvedPath = null): string
    {
        $path = $resolvedPath ?? $this->path;

        // 1. Remove trailing slashes from the base URL
        $cleanedBase = rtrim($baseUrl, '/');

        // 2. Remove leading slashes from the endpoint path
        $cleanedPath = ltrim($path, '/');

        // 3. Recombine them with a single slash. If $cleanedPath is empty, return $cleanedBase.
        if (empty($cleanedPath)) {
            return $cleanedBase;
        }

        return $cleanedBase . '/' . $cleanedPath;
    }
}