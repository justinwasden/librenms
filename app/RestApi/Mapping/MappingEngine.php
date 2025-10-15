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


    public function findMapping(string $metricKey, string $resourceType): ?RestApiMetricFieldMapping
    {
        $cacheKey = "{$resourceType}:{$metricKey}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Try exact match first
        $mapping = $this->exactMatch($metricKey);

        // Try fuzzy match if no exact match
        if (!$mapping) {
            $mapping = $this->fuzzyMatch($metricKey);
        }

        // Try auto-learn if still no match
        if (!$mapping) {
            $mapping = $this->autoLearn($metricKey, $resourceType);
        }

        $this->cache[$cacheKey] = $mapping;

        return $mapping;
    }


    protected function exactMatch(string $metricKey): ?RestApiMetricFieldMapping
    {
        return RestApiMetricFieldMapping::where('api_field_name', $metricKey)
            ->where('enabled', true)
            ->where(function($q) {
                $q->whereNull('device_id')
                  ->orWhere('device_id', $this->device->device_id);
            })
            ->first();
    }


    protected function fuzzyMatch(string $metricKey): ?RestApiMetricFieldMapping
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

            if ($score > $bestScore && $score >= 0.8) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        if ($bestMatch) {
            Log::info("Fuzzy matched '{$metricKey}' to '{$bestMatch->api_field_name}' (score: {$bestScore})");
        }

        return $bestMatch;
    }


    protected function autoLearn(string $metricKey, string $resourceType): ?RestApiMetricFieldMapping
    {
        if ($this->isPaginationMetadata($metricKey)) {
            return null;
        }

        $prediction = $this->predictMapping($metricKey, $resourceType);

        if (!$prediction) {
            return null;
        }

        try {
            $mapping = RestApiMetricFieldMapping::create([
                'device_id' => null,
                'api_field_name' => strtolower($metricKey),
                'librenms_table' => $prediction['table'],
                'librenms_field' => $prediction['field'],
                'unit' => $prediction['unit'] ?? null,
                'transform' => $prediction['transform'] ?? null,
                'confidence_score' => $prediction['confidence'] ?? 0.75,
                'enabled' => true,
                'user_created' => false,
                'last_matched_device_id' => $this->device->device_id,
                'last_seen_at' => now(),
            ]);

            Log::info("✓ Auto-learned: {$metricKey} -> {$prediction['table']}.{$prediction['field']}");

            return $mapping;
        } catch (\Exception $e) {
            Log::error("Failed to auto-learn mapping for {$metricKey}: " . $e->getMessage());
            return null;
        }
    }

    protected function isPaginationMetadata(string $key): bool
    {
        $patterns = [
            '/continuation_token$/',
            '/more_items_remaining$/',
            '/total_item_count$/',
            '/items_count$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }


    protected function predictMapping(string $metricKey, string $resourceType): ?array
    {
        $lower = strtolower($metricKey);

        // Normalize Pure Storage performance endpoints
        if (in_array($resourceType, ['performance', 'performance-by-array'])) {
            $resourceType = str_contains($resourceType, 'array') ? 'volume' : 'array';
        }

        // Storage Controller / Hardware (entPhysical)
        if ($resourceType === 'controller' || $resourceType === 'hardware') {
            if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'entPhysical', 'field' => 'entPhysicalDescr', 'confidence' => 0.95];
            }
            if (preg_match('/(^model$)/i', $lower)) {
                return ['table' => 'entPhysical', 'field' => 'entPhysicalModelName', 'confidence' => 0.95];
            }
            if (preg_match('/(^status$|health$)/i', $lower)) {
                return ['table' => 'entPhysical', 'field' => 'entPhysicalOperStatus', 'confidence' => 0.95];
            }
            if (preg_match('/(^version$|firmware)/i', $lower)) {
                return ['table' => 'entPhysical', 'field' => 'entPhysicalHardwareRev', 'confidence' => 0.95];
            }
            if (preg_match('/(^serial$)/i', $lower)) {
                return ['table' => 'entPhysical', 'field' => 'entPhysicalSerialNum', 'confidence' => 0.95];
            }
            if (preg_match('/(^mode$|type$|class$)/i', $lower)) {
                 return ['table' => 'entPhysical', 'field' => 'entPhysicalClass', 'confidence' => 0.8];
            }
        }

        // Port/Interface (ports)
        if ($resourceType === 'network-interface' || $resourceType === 'port' || str_contains($lower, 'interface') || str_contains($lower, 'port') || str_contains($lower, 'eth')) {
            if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifName', 'confidence' => 0.95];
            }
            if (preg_match('/(descr$|description$)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifDescr', 'confidence' => 0.8];
            }
            if (preg_match('/(mac_address|hardware_addr)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifPhysAddress', 'confidence' => 0.95];
            }
            if (preg_match('/(speed|bandwidth)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifSpeed', 'unit' => 'bps', 'confidence' => 0.95];
            }
            if (preg_match('/(^enabled$)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifAdminStatus', 'transform' => 'boolean_to_updown', 'confidence' => 0.95];
            }
            if (preg_match('/(oper|status|state)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifOperStatus', 'confidence' => 0.85];
            }
            if (preg_match('/(mtu)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifMtu', 'confidence' => 0.95];
            }
            if (preg_match('/(type$|interface_type)/i', $lower)) {
                return ['table' => 'ports', 'field' => 'ifType', 'confidence' => 0.95];
            }
        }

        // Storage (Array or Volume) (storage)
        if ($resourceType === 'array' || $resourceType === 'volume' || str_contains($lower, 'volume')) {
             if (preg_match('/(^name$)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_descr', 'confidence' => 0.9];
            }
            if (preg_match('/(size|capacity|total|provisioned)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_size', 'unit' => 'bytes', 'confidence' => 0.9];
            }
            if (preg_match('/(used|allocated)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_used', 'unit' => 'bytes', 'confidence' => 0.9];
            }
             if (preg_match('/(free|available)/i', $lower)) {
                return ['table' => 'storage', 'field' => 'storage_free', 'unit' => 'bytes', 'confidence' => 0.9];
            }
        }

        // Sensor patterns
        if ($resourceType === 'sensor' || preg_match('/(temp|voltage|current|power|fan|usage)/i', $lower)) {
            return ['table' => 'sensors', 'field' => 'sensor_current', 'confidence' => 0.85];
        }

        // Processor patterns
        if (preg_match('/(cpu|processor).*?(usage|util|load)/i', $lower)) {
            return ['table' => 'processors', 'field' => 'processor_usage', 'unit' => 'percent', 'confidence' => 0.85];
        }

        // Memory patterns
        if (preg_match('/(memory|mem|ram)/i', $lower)) {
            if (preg_match('/used/i', $lower)) {
                return ['table' => 'mempools', 'field' => 'mempool_used', 'unit' => 'bytes', 'confidence' => 0.85];
            }
            if (preg_match('/(free|available)/i', $lower)) {
                return ['table' => 'mempools', 'field' => 'mempool_free', 'unit' => 'bytes', 'confidence' => 0.85];
            }
        }

        return null;
    }


    protected function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        $similarity = 1 - ($distance / $maxLen);

        if (str_contains($str1, $str2) || str_contains($str2, $str1)) {
            $similarity = max($similarity, 0.85);
        }

        return $similarity;
    }


    protected function normalizeMetricKey(string $key): string
    {
        $key = preg_replace('/^(items?_\d+_|device_|storage_|port_|sensor_)/', '', $key);
        $key = preg_replace('/(_\d+|_count|_total)$/', '', $key);
        $key = str_replace(['-', '.', '__'], '_', $key);

        return strtolower($key);
    }


    protected function loadMappingsForDevice(): void
    {
        $mappings = RestApiMetricFieldMapping::where('enabled', true)
            ->where(function($q) {
                $q->whereNull('device_id')
                  ->orWhere('device_id', $this->device->device_id);
            })
            ->get();

        foreach ($mappings as $mapping) {
            $key = ":{$mapping->api_field_name}";
            $this->cache[$key] = $mapping;
        }

        Log::debug("Loaded " . count($mappings) . " mappings for {$this->device->hostname}");
    }
}
