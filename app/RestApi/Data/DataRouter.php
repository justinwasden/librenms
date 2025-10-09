<?php

namespace App\RestApi\Data;

use App\Models\Device;
use App\Models\RestApiMetric;
use LibreNMS\RRD\RrdDefinition;
use Log;

class DataRouter
{
    protected Device $device;
    protected array $mappings;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->loadMappings();
    }

    /**
     * Route data to appropriate storage location
     */
    public function route(array $flattenedData, string $resourceType, array $metricMap = []): void
    {
        foreach ($flattenedData as $key => $value) {
            // Skip non-useful metadata
            if ($this->shouldSkip($key)) {
                continue;
            }

            $routed = false;

            // Try to route using metric mapping first (if provided in endpoint config)
            if (!empty($metricMap) && isset($metricMap[$key])) {
                $routed = $this->routeByMapping($key, $value, $metricMap[$key], $resourceType);
            }

            // If not routed by mapping, check if it's a performance metric for RRD
            if (!$routed && $this->isPerformanceMetric($key, $value)) {
                $rrdName = $this->generateRrdName($key, $resourceType);
                $routed = $this->storeInRrd($rrdName, $key, $value);
            }

            // Fallback: store in rest_api_metrics table
            if (!$routed) {
                $this->storeInFallbackTable($key, $value, $resourceType);
            }
        }
    }

    /**
     * Check if key should be skipped (pagination metadata, etc.)
     */
    protected function shouldSkip(string $key): bool
    {
        $skipPatterns = [
            '/^continuation_token$/',
            '/^more_items_remaining$/',
            '/^total_item_count$/',
            '/^items_count$/',  // This is just metadata
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Route data based on explicit metric mapping
     */
    protected function routeByMapping(string $key, $value, string $mapping, string $resourceType): bool
    {
        // Parse mapping like "rrd.disk_usage" or "table.storage.size"
        $parts = explode('.', $mapping);
        
        if (count($parts) < 2) {
            return false;
        }

        $destination = $parts[0]; // 'rrd' or 'table'

        if ($destination === 'rrd') {
            $rrdName = $parts[1] ?? $key;
            return $this->storeInRrd($rrdName, $key, $value);
        } elseif ($destination === 'table') {
            // Future: store in specific LibreNMS tables
            Log::debug("Table storage not yet implemented for: {$key}");
            return false;
        }

        return false;
    }

    /**
     * Check if this is a performance metric
     */
    protected function isPerformanceMetric(string $key, $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        // Performance metric patterns
        $patterns = [
            '/_(usage|percent|rate|count|bytes|packets|errors|drops|utilization|throughput)$/',
            '/^(cpu|memory|disk|network|bandwidth|latency|iops|load)_/',
            '/(read|write|tx|rx)_(bytes|packets|rate|errors)/',
            '/_(total|current|average|max|min|peak)$/',
            '/_per_(second|minute|hour)$/',
        ];

        $lowerKey = strtolower($key);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lowerKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store data in RRD file
     */
    protected function storeInRrd(string $rrdName, string $key, $value): bool
    {
        if (!is_numeric($value)) {
            Log::debug("Skipping non-numeric RRD value for {$key}");
            return false;
        }

        try {
            $datastore = app('Datastore');
            
            // Sanitize RRD dataset name (max 19 chars)
            $dsName = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
            $dsName = substr($dsName, 0, 19);
            
            $rrd_def = RrdDefinition::make()->addDataset($dsName, 'GAUGE', 0, 125000000000);
            
            $datastore->put(
                ['device_id' => $this->device->device_id],
                "rest_api_{$rrdName}",
                [
                    'rrd_def' => $rrd_def,
                    'rrd_name' => ['rest_api', $rrdName],
                ],
                $value
            );
            
            Log::debug("Stored in RRD: rest_api_{$rrdName} = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error storing RRD {$rrdName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in fallback table
     */
    protected function storeInFallbackTable(string $key, $value, string $resourceType): void
    {
        try {
            RestApiMetric::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'metric_key' => $key,
                    'resource_type' => $resourceType,
                ],
                [
                    'metric_value' => is_array($value) ? json_encode($value) : (string) $value,
                    'last_updated' => now(),
                ]
            );
            
            Log::debug("Stored in fallback table: {$key} ({$resourceType})");
        } catch (\Exception $e) {
            Log::error("Error storing in fallback table: " . $e->getMessage());
        }
    }

    /**
     * Generate RRD file name
     */
    protected function generateRrdName(string $key, string $resourceType): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $name = strtolower($name);
        
        // Add resource type prefix if useful
        if (!str_starts_with($name, $resourceType . '_')) {
            $name = $resourceType . '_' . $name;
        }
        
        return $name;
    }

    /**
     * Load mappings configuration
     */
    protected function loadMappings(): void
    {
        // Placeholder for future table-specific mappings
        $this->mappings = [];
    }
}
