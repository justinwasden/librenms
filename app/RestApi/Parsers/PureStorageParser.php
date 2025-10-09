<?php
/**
 * File: app/Parsers/PureStorageParser.php
 * Purpose: Parse and flatten Pure Storage API responses for LibreNMS.
 */

namespace App\Parsers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PureStorageParser
{
    /**
     * Parse a raw JSON API response into a normalized, flattened metric array.
     *
     * @param array $response The decoded JSON from Pure Storage API.
     * @param string|null $resource Optional resource hint (e.g. "arrays", "volumes", "network-interfaces").
     * @return array Flattened associative array of metrics.
     */
    public function parseResponse(array $response, ?string $resource = null): array
    {
        $parsed = [];

        // Determine resource if not provided
        $resource = $resource ?? $this->detectResourceType($response);

        // Many Pure APIs wrap data under 'items'
        if (isset($response['items']) && is_array($response['items'])) {
            foreach ($response['items'] as $index => $item) {
                $flattened = $this->flattenArray($item);
                $parsed[] = [
                    'resource_type' => $resource,
                    'index'         => $index,
                    'metrics'       => $flattened,
                ];
            }
        } else {
            // Direct structure (some endpoints return top-level data)
            $flattened = $this->flattenArray($response);
            $parsed[] = [
                'resource_type' => $resource,
                'index'         => 0,
                'metrics'       => $flattened,
            ];
        }

        return $parsed;
    }

    /**
     * Flatten nested arrays/objects into a single-level associative array.
     * Example: ['space' => ['total_used' => 5]]  ['space_total_used' => 5]
     */
    protected function flattenArray(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}_{$key}" : $key;

            if (is_array($value)) {
                // Recursively flatten nested structures
                $result += $this->flattenArray($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Try to detect the resource type based on keys in the response.
     */
    protected function detectResourceType(array $response): string
    {
        // Handle "items" wrapper
        if (isset($response['items']) && is_array($response['items'])) {
            $first = $response['items'][0] ?? [];
            return $this->detectResourceType($first);
        }

        $keys = array_keys($response);

        if (Arr::has($response, 'space.total_physical')) {
            return 'arrays';
        }
        if (Arr::has($response, 'network_interface') || in_array('rx_bytes_per_sec', $keys)) {
            return 'network-interfaces';
        }
        if (Arr::has($response, 'volume_id') || Arr::has($response, 'writes_per_sec')) {
            return 'volumes';
        }
        if (Arr::has($response, 'controller') || Arr::has($response, 'cpu')) {
            return 'controllers';
        }

        return 'unknown';
    }

    /**
     * Predict mapping keys (metric name  JSON key) for newly discovered Pure resources.
     * This avoids manually writing long paths like "items.space.total_physical".
     */
    public function predictMapping(array $sampleMetrics, string $resource): array
    {
        $mapping = [];

        foreach ($sampleMetrics as $key => $value) {
            $lowerKey = strtolower($key);

            // Match common Pure metric patterns
            if (Str::contains($lowerKey, 'total_physical')) {
                $mapping["{$resource}_total_physical_space"] = $key;
            } elseif (Str::contains($lowerKey, 'total_provisioned')) {
                $mapping["{$resource}_total_provisioned"] = $key;
            } elseif (Str::contains($lowerKey, 'total_used')) {
                $mapping["{$resource}_total_used"] = $key;
            } elseif (Str::contains($lowerKey, 'data_reduction')) {
                $mapping["{$resource}_data_reduction"] = $key;
            } elseif (Str::contains($lowerKey, 'unique_effective')) {
                $mapping["{$resource}_unique_effective"] = $key;
            } elseif (Str::contains($lowerKey, 'shared_effective')) {
                $mapping["{$resource}_shared_effective"] = $key;
            } elseif (Str::contains($lowerKey, 'snapshots_effective')) {
                $mapping["{$resource}_snapshots_effective"] = $key;
            } elseif (Str::contains($lowerKey, 'replication')) {
                $mapping["{$resource}_replication_bytes"] = $key;
            } elseif (Str::contains($lowerKey, 'latency')) {
                $mapping["{$resource}_latency_ms"] = $key;
            } elseif (Str::contains($lowerKey, 'iops')) {
                $mapping["{$resource}_iops"] = $key;
            } elseif (Str::contains($lowerKey, 'bandwidth')) {
                $mapping["{$resource}_bandwidth_bytes_per_sec"] = $key;
            } elseif (Str::contains($lowerKey, 'capacity')) {
                $mapping["{$resource}_capacity_bytes"] = $key;
            } elseif (Str::contains($lowerKey, 'used_provisioned')) {
                $mapping["{$resource}_used_provisioned"] = $key;
            } elseif (Str::contains($lowerKey, 'virtual')) {
                $mapping["{$resource}_virtual_space"] = $key;
            } elseif (Str::contains($lowerKey, 'parity')) {
                $mapping["{$resource}_parity_ratio"] = $key;
            }
        }

        return $mapping;
    }
}
