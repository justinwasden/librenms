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
                    return $this->storeInStorageTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
                case 'ports':
                    return $this->storeInPortsTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
                case 'sensors':
                    return $this->storeInSensorsTable($mapping->librenms_field, $transformedValue, $mapping->unit, $endpointName, $displayKey ?? $key, $mapping);
                    
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
    protected function storeInStorageTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            // Extract storage identifier from displayKey if possible
            $storageDescr = $this->extractStorageDescr($displayKey, $mapping);
            
            // Find existing or create new storage entry
            $storage = Storage::where('device_id', $this->device->device_id)
                ->where('storage_descr', $storageDescr)
                ->first();
            
            if (!$storage) {
                $storage = new Storage();
                $storage->device_id = $this->device->device_id;
                $storage->storage_descr = $storageDescr;
                $storage->storage_index = (string)abs(crc32($storageDescr));
                $storage->storage_type = 'rest-api';
                $storage->type = 'rest-api';
                $storage->storage_size = 0;
                $storage->storage_units = 1;
                $storage->storage_used = 0;
                $storage->storage_free = 0;
                $storage->storage_perc = 0;
                $storage->save();
            }
            
            // Update the field
            $storage->update([$field => $value]);
            
            Log::info("[{$endpointName}] {$displayKey} -> storage.{$field} (descr: {$storageDescr}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in ports table
     */
    protected function storeInPortsTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            // Extract port name from displayKey
            $portName = $this->extractPortName($displayKey, $mapping);
            
            // Find existing or create new port entry
            $port = Port::where('device_id', $this->device->device_id)
                ->where('ifName', $portName)
                ->first();
            
            if (!$port) {
                $port = new Port();
                $port->device_id = $this->device->device_id;
                $port->ifName = $portName;
                $port->ifDescr = "REST API Port: {$portName}";
                $port->port_descr_type = 'rest-api';
                $port->ifIndex = abs(crc32($portName));
                $port->save();
            }
            
            // Update the field
            $port->update([$field => $value]);
            
            Log::info("[{$endpointName}] {$displayKey} -> ports.{$field} (port: {$portName}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in ports.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in sensors table
     */
    protected function storeInSensorsTable(string $field, $value, ?string $unit = null, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            // Sensors can only store numeric values in sensor_current
            if ($field === 'sensor_current' && !is_numeric($value)) {
                Log::debug("[{$endpointName}] Skipping non-numeric sensor value for {$displayKey}: {$value}");
                return false;
            }
            
            // Determine sensor type and class
            $sensorInfo = $this->determineSensorType($field, $unit, $displayKey);
            
            // Create sensor description from displayKey
            $sensorDescr = str_replace('_', ' ', ucwords($displayKey, '_'));
            $sensorDescr = preg_replace('/\s+/', ' ', $sensorDescr);
            
            // Create a unique sensor index
            $sensorIndex = abs(crc32($this->device->device_id . '_' . $displayKey . '_' . $endpointName));
            
            // Find existing or create new sensor
            $sensor = Sensor::where('device_id', $this->device->device_id)
                ->where('sensor_class', $sensorInfo['class'])
                ->where('sensor_type', 'rest-api')
                ->where('sensor_index', $sensorIndex)
                ->first();
            
            if (!$sensor) {
                $sensor = new Sensor();
                $sensor->device_id = $this->device->device_id;
                $sensor->sensor_class = $sensorInfo['class'];
                $sensor->sensor_type = 'rest-api';
                $sensor->sensor_index = (string)$sensorIndex;
                $sensor->sensor_descr = $sensorDescr;
                $sensor->sensor_oid = 'rest-api.' . $displayKey;
                $sensor->poller_type = 'rest-api';
                
                // Set default limits
                if ($sensorInfo['class'] === 'temperature') {
                    $sensor->sensor_limit = 70;
                    $sensor->sensor_limit_low = 10;
                } elseif ($sensorInfo['class'] === 'percentage') {
                    $sensor->sensor_limit = 90;
                    $sensor->sensor_limit_warn = 80;
                    $sensor->sensor_limit_low = 0;
                }
                
                $sensor->save();
            }
            
            // Update sensor current value
            $updateData = ['sensor_current' => $value];
            $sensor->update($updateData);
            
            Log::info("[{$endpointName}] {$displayKey} -> sensors.sensor_current (class: {$sensorInfo['class']}, descr: {$sensorDescr}) = {$value}" . ($unit ? " ({$unit})" : ''));
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store sensor {$field}: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Determine sensor type and class from field name and unit
     */
    protected function determineSensorType(string $field, ?string $unit, string $displayKey): array
    {
        $field = strtolower($field);
        $displayKey = strtolower($displayKey);
        
        // Temperature sensors
        if (strpos($field, 'temp') !== false || strpos($displayKey, 'tmp') !== false || 
            strpos($displayKey, 'temperature') !== false || $unit === '°C' || $unit === 'celsius') {
            return [
                'class' => 'temperature',
                'description' => 'Temperature',
            ];
        }
        
        // Voltage sensors
        if (strpos($field, 'voltage') !== false || $unit === 'V' || $unit === 'volts') {
            return [
                'class' => 'voltage',
                'description' => 'Voltage',
            ];
        }
        
        // Fan speed
        if (strpos($field, 'fan') !== false || strpos($displayKey, 'fan') !== false || $unit === 'RPM') {
            return [
                'class' => 'fanspeed',
                'description' => 'Fan Speed',
            ];
        }
        
        // Power
        if (strpos($field, 'power') !== false || $unit === 'W' || $unit === 'watts') {
            return [
                'class' => 'power',
                'description' => 'Power',
            ];
        }
        
        // Current
        if (strpos($field, 'ampere') !== false || $unit === 'A' || $unit === 'amps') {
            return [
                'class' => 'current',
                'description' => 'Current',
            ];
        }
        
        // Percentage/ratio
        if (strpos($field, 'percent') !== false || strpos($field, 'perc') !== false || 
            strpos($field, 'usage') !== false || strpos($field, 'util') !== false ||
            $unit === '%' || $unit === 'ratio') {
            return [
                'class' => 'percentage',
                'description' => 'Percentage',
            ];
        }
        
        // Count/state
        if (strpos($field, 'count') !== false || strpos($field, 'state') !== false || 
            strpos($field, 'status') !== false) {
            return [
                'class' => 'count',
                'description' => 'Count',
            ];
        }
        
        // Default to state
        return [
            'class' => 'state',
            'description' => 'State',
        ];
    }

    /**
     * Extract storage description from display key
     */
    protected function extractStorageDescr(string $displayKey, $mapping = null): string
    {
        // Try to extract a meaningful name from the key
        // Example: "volume_sw_sql_rsa_swsql_01_space_total_used" -> "sw-sql-rsa-swsql-01"
        
        // Remove common suffixes
        $name = preg_replace('/(space|total|used|free|size|percent|perc|_)+$/', '', $displayKey);
        $name = trim($name, '_');
        
        // If name is still too generic or empty, use endpoint info from mapping
        if (strlen($name) < 3 || in_array($name, ['volume', 'storage', 'disk'])) {
            $name = 'rest-api-storage';
        }
        
        // Clean up the name
        $name = str_replace('_', '-', $name);
        $name = substr($name, 0, 64); // Limit length
        
        return $name;
    }

    /**
     * Extract port name from display key
     */
    protected function extractPortName(string $displayKey, $mapping = null): string
    {
        // Try to extract port identifier
        // Examples: 
        // "ct0_eth10_speed" -> "ct0.eth10"
        // "network_ct1_eth11_mtu" -> "ct1.eth11"
        
        if (preg_match('/(ct[0-9]+[_\.]eth[0-9]+)/i', $displayKey, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }
        
        if (preg_match('/(eth[0-9]+)/i', $displayKey, $matches)) {
            return $matches[1];
        }
        
        // Fallback: use the whole displayKey
        return substr(str_replace('_', '.', $displayKey), 0, 32);
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
