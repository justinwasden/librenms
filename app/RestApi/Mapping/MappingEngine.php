<?php

namespace App\RestApi\Mapping;

use App\Models\Device;
use App\Models\MetricFieldMapping;
use Illuminate\Support\Str;
use Log;

class MappingEngine
{
    protected Device $device;
    protected array $cache = [];
    
    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->loadMappingsForDevice();
    }
    
    /**
     * Find mapping for a metric
     */
    public function findMapping(string $metricKey, string $resourceType): ?MetricFieldMapping
    {
        // Remove resource_type prefix if present (e.g., "sensor__status" -> "status")
        $cleanKey = $this->removeResourcePrefix($metricKey, $resourceType);
        
        $cacheKey = "{$resourceType}:{$cleanKey}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Try exact match first
        $mapping = $this->exactMatch($cleanKey, $resourceType);
        
        // Try fuzzy match if no exact match
        if (!$mapping) {
            $mapping = $this->fuzzyMatch($cleanKey, $resourceType);
        }
        
        // Try auto-learn if still no match
        if (!$mapping) {
            $mapping = $this->autoLearn($cleanKey, $resourceType);
        }
        
        $this->cache[$cacheKey] = $mapping;
        
        return $mapping;
    }
    
    /**
     * Remove resource type prefix from metric key
     */
    protected function removeResourcePrefix(string $key, string $resourceType): string
    {
        // Remove patterns like "sensor__", "device__", "storage__", etc.
        $prefix = strtolower($resourceType) . '__';
        
        if (str_starts_with(strtolower($key), $prefix)) {
            return substr($key, strlen($prefix));
        }
        
        return $key;
    }
    
    /**
     * Exact match lookup
     */
    protected function exactMatch(string $metricKey, string $resourceType): ?MetricFieldMapping
    {
        return MetricFieldMapping::forMetric($metricKey, $resourceType)
            ->forDevice($this->device)
            ->first();
    }
    
    /**
     * Fuzzy matching using pattern recognition
     */
    protected function fuzzyMatch(string $metricKey, string $resourceType): ?MetricFieldMapping
    {
        $normalized = $this->normalizeMetricKey($metricKey);
        
        // Get all enabled mappings for this resource type
        $candidates = MetricFieldMapping::where('enabled', true)
            ->where(function($q) use ($resourceType) {
                $q->where('resource_type', $resourceType)
                  ->orWhereNull('resource_type');
            })
            ->forDevice($this->device)
            ->get();
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($candidates as $candidate) {
            $score = $this->calculateSimilarity($normalized, $candidate->metric_name);
            
            if ($score > $bestScore && $score >= 0.8) { // 80% similarity threshold
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }
        
        if ($bestMatch) {
            Log::info("Fuzzy matched '{$metricKey}' to '{$bestMatch->metric_name}' (score: {$bestScore})");
        }
        
        return $bestMatch;
    }
    
    /**
     * Auto-learn new mapping
     */
    protected function autoLearn(string $metricKey, string $resourceType): ?MetricFieldMapping
    {
        // Never auto-learn pagination metadata
        if ($this->isPaginationMetadata($metricKey)) {
            return null;
        }
        
        // Detect likely target table/field from metric name patterns
        $prediction = $this->predictMapping($metricKey, $resourceType);
        
        if (!$prediction) {
            return null;
        }
        
        // Create auto-learned mapping
        try {
            $mapping = MetricFieldMapping::create([
                'metric_name' => strtolower($metricKey),
                'resource_type' => strtolower($resourceType),
                'vendor' => $this->device->vendor ?? null,
                'os' => $this->device->os ?? null,
                'librenms_table' => $prediction['table'],
                'librenms_field' => $prediction['field'],
                'data_type' => $prediction['data_type'],
                'unit' => $prediction['unit'] ?? null,
                'multiplier' => $prediction['multiplier'] ?? 1.0,
                'auto_learned' => true,
                'enabled' => false, // Disabled until user reviews
                'last_matched_device_id' => $this->device->device_id,
                'last_seen_at' => now(),
                'description' => "Auto-learned from {$this->device->hostname}",
            ]);
            
            Log::info("Auto-learned mapping: {$metricKey} -> {$prediction['table']}.{$prediction['field']}");
            
            return null; // Return null since it's disabled
        } catch (\Exception $e) {
            Log::error("Failed to auto-learn mapping: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if this is pagination metadata that should never be auto-learned
     */
    protected function isPaginationMetadata(string $key): bool
    {
        $patterns = [
            '/continuation_token$/',
            '/more_items_remaining$/',
            '/total_item_count$/',
            '/items_count$/',
            '/^items_\d+_/',  // items_0_, items_1_, etc.
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Predict mapping based on metric name patterns
     */
    protected function predictMapping(string $metricKey, string $resourceType): ?array
    {
        $lower = strtolower($metricKey);
        
        // Storage patterns
        if ($resourceType === 'storage' || str_contains($lower, 'volume') || str_contains($lower, 'disk')) {
            if (preg_match('/(size|capacity|total|provisioned)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_size', 'data_type' => 'numeric', 'unit' => 'bytes'];
            }
            if (preg_match('/(used|allocated)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_used', 'data_type' => 'numeric', 'unit' => 'bytes'];
            }
            if (preg_match('/(free|available)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_free', 'data_type' => 'numeric', 'unit' => 'bytes'];
            }
            if (preg_match('/(name|descr|label)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_descr', 'data_type' => 'string'];
            }
        }
        
        // Port/Interface patterns
        if ($resourceType === 'port' || str_contains($lower, 'interface') || str_contains($lower, 'port')) {
            if (preg_match('/(speed|bandwidth)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifSpeed', 'data_type' => 'numeric', 'unit' => 'bps'];
            }
            if (preg_match('/(oper|status|state)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifOperStatus', 'data_type' => 'string'];
            }
            if (preg_match('/(name|descr)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifDescr', 'data_type' => 'string'];
            }
        }
        
        // Sensor patterns
        if ($resourceType === 'sensor' || preg_match('/(temp|voltage|current|power|fan)/i', $lower)) {
            return ['table' => 'sensors', 'field' => 'sensor_current', 'data_type' => 'numeric'];
        }
        
        // Processor patterns
        if (preg_match('/(cpu|processor).*?(usage|util|load)/i', $lower)) {
            return ['table' => 'processors', 'field' => 'processor_usage', 'data_type' => 'numeric', 'unit' => 'percent'];
        }
        
        // Memory patterns
        if (preg_match('/(memory|mem|ram)/i', $lower)) {
            if (preg_match('/used/i', $lower)) {
                return ['table' => 'mempools', 'field' => 'mempool_used', 'data_type' => 'numeric', 'unit' => 'bytes'];
            }
            if (preg_match('/(free|available)/i', $lower)) {
                return ['table' => 'mempools', 'field' => 'mempool_free', 'data_type' => 'numeric', 'unit' => 'bytes'];
            }
        }
        
        return null;
    }
    
    /**
     * Calculate similarity between two strings
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);
        
        // Exact match
        if ($str1 === $str2) {
            return 1.0;
        }
        
        // Levenshtein distance
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        $similarity = 1 - ($distance / $maxLen);
        
        // Boost score if one contains the other
        if (str_contains($str1, $str2) || str_contains($str2, $str1)) {
            $similarity = max($similarity, 0.85);
        }
        
        return $similarity;
    }
    
    /**
     * Normalize metric key for matching
     */
    protected function normalizeMetricKey(string $key): string
    {
        // Remove common prefixes/suffixes
        $key = preg_replace('/^(items?_\d+_|device_|storage_|port_|sensor_)/', '', $key);
        $key = preg_replace('/(_\d+|_count|_total)$/', '', $key);
        
        // Replace separators
        $key = str_replace(['-', '.', '__'], '_', $key);
        
        return strtolower($key);
    }
    
    /**
     * Load all mappings for this device into cache
     */
    protected function loadMappingsForDevice(): void
    {
        $mappings = MetricFieldMapping::where('enabled', true)
            ->forDevice($this->device)
            ->get();
        
        foreach ($mappings as $mapping) {
            $key = "{$mapping->resource_type}:{$mapping->metric_name}";
            $this->cache[$key] = $mapping;
        }
        
        Log::debug("Loaded " . count($mappings) . " mappings for {$this->device->hostname}");
    }
}
