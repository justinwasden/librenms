<?php

namespace App\RestApi\Mapping;

use App\Models\Device;
use App\Models\RestApiMetricFieldMapping;
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
    public function findMapping(string $metricKey, string $resourceType): ?RestApiMetricFieldMapping
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
            $mapping = $this->autoLearn($cleanKey, $resourceType, $metricKey);
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
    protected function exactMatch(string $metricKey, string $resourceType): ?RestApiMetricFieldMapping
    {
        return RestApiMetricFieldMapping::where('api_field_name', $metricKey)
            ->where('enabled', true)
            ->where(function($q) use ($resourceType) {
                $q->whereNull('device_id')
                  ->orWhere('device_id', $this->device->device_id);
            })
            ->first();
    }
    
    /**
     * Fuzzy matching using pattern recognition
     */
    protected function fuzzyMatch(string $metricKey, string $resourceType): ?RestApiMetricFieldMapping
    {
        $normalized = $this->normalizeMetricKey($metricKey);
        
        // Get all enabled mappings
        $candidates = RestApiMetricFieldMapping::where('enabled', true)
            ->where(function($q) {
                $q->whereNull('device_id')
                  ->orWhere('device_id', $this->device->device_id);
            })
            ->get();
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($candidates as $candidate) {
            $score = $this->calculateSimilarity($normalized, $candidate->api_field_name);
            
            if ($score > $bestScore && $score >= 0.8) { // 80% similarity threshold
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }
        
        if ($bestMatch) {
            Log::info("Fuzzy matched '{$metricKey}' to '{$bestMatch->api_field_name}' (score: {$bestScore})");
        }
        
        return $bestMatch;
    }
    
    /**
     * Auto-learn new mapping
     */
    protected function autoLearn(string $metricKey, string $resourceType, string $originalKey): ?RestApiMetricFieldMapping
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
            $mapping = RestApiMetricFieldMapping::create([
                'device_id' => null, // Global mapping
                'api_field_name' => strtolower($metricKey),
                'librenms_table' => $prediction['table'],
                'librenms_field' => $prediction['field'],
                'unit' => $prediction['unit'] ?? null,
                'transform' => null,
                'confidence_score' => $prediction['confidence'] ?? 0.75,
                'enabled' => true, // Enable auto-learned mappings
                'user_created' => false,
                'last_matched_device_id' => $this->device->device_id,
                'last_seen_at' => now(),
            ]);
            
            Log::info("✓ Auto-learned: {$metricKey} -> {$prediction['table']}.{$prediction['field']} (confidence: {$prediction['confidence']})");
            
            return $mapping;
        } catch (\Exception $e) {
            Log::error("Failed to auto-learn mapping for {$metricKey}: " . $e->getMessage());
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
        
        // Storage Array patterns (device-level array metrics)
        if ($resourceType === 'array') {
            if (preg_match('/(^name$|^version$|^model$|^serial)/i', $lower)) {
                $field = $lower;
                if ($field === 'serial') $field = 'serial_number';
                return ['table' => 'storage_arrays', 'field' => $field, 'confidence' => 0.95];
            }
            if (preg_match('/(capacity|space.*total)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'total_capacity', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(physical)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'total_physical', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(^used$|space.*used)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'total_used', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(provisioned)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'total_provisioned', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(snapshot)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'snapshots', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(data.*reduction|data_reduction)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'data_reduction', 'confidence' => 0.9];
            }
            if (preg_match('/(total.*reduction)/i', $lower)) {
                return ['table' => 'storage_arrays', 'field' => 'total_reduction', 'confidence' => 0.9];
            }
        }
        
        // Storage Controller patterns
        if ($resourceType === 'controller') {
            if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'storage_controllers', 'field' => 'name', 'confidence' => 0.95];
            }
            if (preg_match('/(^model$)/i', $lower)) {
                return ['table' => 'storage_controllers', 'field' => 'model', 'confidence' => 0.95];
            }
            if (preg_match('/(^status$)/i', $lower)) {
                return ['table' => 'storage_controllers', 'field' => 'status', 'confidence' => 0.95];
            }
            if (preg_match('/(^mode$)/i', $lower)) {
                return ['table' => 'storage_controllers', 'field' => 'mode', 'confidence' => 0.95];
            }
            if (preg_match('/(^version$|firmware)/i', $lower)) {
                return ['table' => 'storage_controllers', 'field' => 'version', 'confidence' => 0.95];
            }
        }
        
        // Storage Array Host patterns
        if ($resourceType === 'host') {
            if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'name', 'confidence' => 0.95];
            }
            if (preg_match('/(iqn)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'iqns', 'confidence' => 0.95];
            }
            if (preg_match('/(wwn)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'wwns', 'confidence' => 0.95];
            }
            if (preg_match('/(connection.*count|path.*count)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'connection_count', 'confidence' => 0.9];
            }
            if (preg_match('/(connectivity|connected)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'port_connectivity_status', 'confidence' => 0.85];
            }
            if (preg_match('/(host.*group|hgroup)/i', $lower)) {
                return ['table' => 'storage_array_hosts', 'field' => 'host_group', 'confidence' => 0.9];
            }
        }
        
        // Storage Array Volume patterns
        if ($resourceType === 'volume') {
            if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'name', 'confidence' => 0.95];
            }
            if (preg_match('/(^serial$)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'serial', 'confidence' => 0.95];
            }
            if (preg_match('/(provisioned.*total|^size$)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'total_provisioned', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(provisioned.*used)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'used_provisioned', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(physical)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'total_physical', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(snapshot)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'snapshots', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(data.*reduction|data_reduction)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'data_reduction', 'confidence' => 0.9];
            }
            if (preg_match('/(pod)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'pod_name', 'confidence' => 0.9];
            }
            if (preg_match('/(volume.*group|vgroup)/i', $lower)) {
                return ['table' => 'storage_array_volumes', 'field' => 'volume_group', 'confidence' => 0.9];
            }
        }
        
        // Storage patterns (legacy LibreNMS storage table)
        if ($resourceType === 'storage' || str_contains($lower, 'volume') || str_contains($lower, 'disk')) {
            if (preg_match('/(size|capacity|total|provisioned)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_size', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(used|allocated)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_used', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(free|available)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_free', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(name|descr|label)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_descr', 'confidence' => 0.8];
            }
        }
        
        // Port/Interface patterns
        if ($resourceType === 'port' || str_contains($lower, 'interface') || str_contains($lower, 'port') || str_contains($lower, 'eth')) {
            if (preg_match('/(speed|bandwidth)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifSpeed', 'unit' => 'bps', 'confidence' => 0.95];
            }
            if (preg_match('/(oper|status|state)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifOperStatus', 'confidence' => 0.85];
            }
            if (preg_match('/(name|descr)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifDescr', 'confidence' => 0.8];
            }
            if (preg_match('/(mtu)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifMtu', 'confidence' => 0.95];
            }
        }
        
        // Sensor patterns
        if ($resourceType === 'sensor' || preg_match('/(temp|voltage|current|power|fan)/i', $lower)) {
            return ['table' => 'sensors', 'field' => 'sensor_current', 'confidence' => 0.85];
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
