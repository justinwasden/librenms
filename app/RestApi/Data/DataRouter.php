<?php

namespace App\RestApi\Data;

use App\Models\Device;
use App\Models\RestApiMetric;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\RestApi\Mapping\MappingEngine;
use LibreNMS\RRD\RrdDefinition;
use Log;

class DataRouter
{
    protected Device $device;
    protected MappingEngine $mappingEngine;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->mappingEngine = new MappingEngine($device);
    }

    /**
     * Route data to appropriate storage location
     */
    public function route(array $flattenedData, string $resourceType, array $metricMap = [], string $endpointName = 'unknown'): void
    {
        foreach ($flattenedData as $key => $value) {
            // Skip pagination metadata
            if ($this->shouldSkip($key)) {
                continue;
            }

            // Clean the key for display (remove resource type prefix)
            $displayKey = preg_replace('/^(device|storage|port|sensor|custom|mempool|processor)__/', '', $key);

            $routed = false;

            // 1. Try explicit metric mapping from endpoint config
            if (!empty($metricMap) && isset($metricMap[$key])) {
                $routed = $this->routeByMapping($key, $value, $metricMap[$key], $resourceType, $endpointName, $displayKey);
            }

            // 2. Try intelligent mapping using MappingEngine
            if (!$routed) {
                $mapping = $this->mappingEngine->findMapping($key, $resourceType);
                if ($mapping && $mapping->enabled) {
                    Log::info("✓ [{$endpointName}] {$displayKey} -> {$mapping->librenms_table}.{$mapping->librenms_field}");
                    $routed = $this->storeUsingMapping($mapping, $key, $value, $endpointName, $displayKey);
                }
            }

            // 3. Check if it's a performance metric for RRD
            if (!$routed && $this->isPerformanceMetric($key, $value)) {
                $rrdName = $this->generateRrdName($key, $resourceType);
                $routed = $this->storeInRrd($rrdName, $key, $value, $endpointName, $displayKey);
            }

            // 4. Fallback: store in rest_api_metrics table
            if (!$routed) {
                $this->storeInFallbackTable($key, $value, $resourceType, $endpointName, $displayKey);
            }
        }
    }

    /**
     * Store data using a MetricFieldMapping
     */
    protected function storeUsingMapping($mapping, string $key, $value, string $endpointName = 'unknown', string $displayKey = null): bool
    {
        try {
            // Transform value according to mapping
            $transformedValue = $mapping->transformValue($value);
            
            if ($transformedValue === null && $value !== null) {
                Log::debug("Value transformation returned null for {$key}");
                return false;
            }

            // Update the mapping's last_seen
            $mapping->update([
                'last_matched_device_id' => $this->device->device_id,
                'last_seen_at' => now(),
            ]);

            // Route to appropriate LibreNMS table
            switch ($mapping->librenms_table) {
                case 'storage':
                    return $this->storeInStorageTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key);
                    
                case 'ports':
                    return $this->storeInPortsTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key);
                    
                case 'sensors':
                    return $this->storeInSensorsTable($mapping->librenms_field, $transformedValue, $mapping->unit, $endpointName, $displayKey ?? $key);
                    
                case 'devices':
                    return $this->storeInDevicesTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key);
                    
                default:
                    Log::debug("No handler for table: {$mapping->librenms_table}");
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Error storing with mapping: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in storage table
     */
    protected function storeInStorageTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = ''): bool
    {
        // For now, log what would be stored
        // TODO: Implement actual storage table update
        Log::info("[{$endpointName}] {$displayKey} -> storage.{$field} = {$value}");
        return true;
    }

    /**
     * Store in ports table
     */
    protected function storeInPortsTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = ''): bool
    {
        Log::info("[{$endpointName}] {$displayKey} -> ports.{$field} = {$value}");
        return true;
    }

    /**
     * Store in sensors table
     */
    protected function storeInSensorsTable(string $field, $value, ?string $unit = null, string $endpointName = 'unknown', string $displayKey = ''): bool
    {
        Log::info("[{$endpointName}] {$displayKey} -> sensors.{$field} = {$value}" . ($unit ? " ({$unit})" : ''));
        return true;
    }

    /**
     * Store in devices table
     */
    protected function storeInDevicesTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = ''): bool
    {
        try {
            $this->device->update([$field => $value]);
            Log::info("[{$endpointName}] {$displayKey} -> device.{$field} = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to update device.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if key should be skipped
     */
    protected function shouldSkip(string $key): bool
    {
        // Remove any resource prefix first
        $cleanKey = preg_replace('/^(device|storage|port|sensor|custom|mempool|processor)__/', '', strtolower($key));
        
        $skipPatterns = [
            '/^continuation_token$/',
            '/^more_items_remaining$/',
            '/^total_item_count$/',
            '/^items_count$/',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $cleanKey)) {
                Log::debug("Skipping metadata: {$key}");
                return true;
            }
        }

        return false;
    }

    /**
     * Route data based on explicit metric mapping
     */
    protected function routeByMapping(string $key, $value, string $mapping, string $resourceType, string $endpointName = 'unknown', string $displayKey = null): bool
    {
        $parts = explode('.', $mapping);
        
        if (count($parts) < 2) {
            return false;
        }

        $destination = $parts[0];

        if ($destination === 'rrd') {
            $rrdName = $parts[1] ?? $key;
            return $this->storeInRrd($rrdName, $key, $value, $endpointName, $displayKey ?? $key);
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
    protected function storeInRrd(string $rrdName, string $key, $value, string $endpointName = 'unknown', string $displayKey = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        try {
            $datastore = app('Datastore');
            
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
            
            $display = $displayKey ?? $key;
            Log::debug("[{$endpointName}] {$display} -> RRD: rest_api_{$rrdName} = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error storing RRD {$rrdName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in fallback table
     */
    protected function storeInFallbackTable(string $key, $value, string $resourceType, string $endpointName = 'unknown', string $displayKey = null): void
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
            
            $display = $displayKey ?? $key;
            Log::debug("[{$endpointName}] {$display} -> fallback table ({$resourceType})");
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
        
        if (!str_starts_with($name, $resourceType . '_')) {
            $name = $resourceType . '_' . $name;
        }
        
        return $name;
    }
}
