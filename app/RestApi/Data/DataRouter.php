<?php

namespace App\RestApi\Data;

use App\Models\Device;
use App\Models\RestApiMetric;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\Models\StorageArray;
use App\Models\StorageController;
use App\Models\StorageArrayHost;
use App\Models\StorageArrayVolume;
use App\RestApi\Mapping\MappingEngine;
use LibreNMS\RRD\RrdDefinition;
use Log;

class DataRouter
{
    protected Device $device;
    protected MappingEngine $mappingEngine;
    protected array $itemContext = [];

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->mappingEngine = new MappingEngine($device);
    }

    /**
     * Route data to appropriate storage location
     */
    public function route(array $flattenedData, string $resourceType, array $metricMap = [], string $endpointName = 'unknown', array $itemContext = []): void
    {
        // Store item context for use in extraction methods
        $this->itemContext = $itemContext;
        
        foreach ($flattenedData as $key => $value) {
            // Skip pagination metadata
            if ($this->shouldSkip($key)) {
                continue;
            }

            $displayKey = $key;
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
                case 'storage_arrays':
                    return $this->storeInStorageArrayTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
                case 'storage_controllers':
                    return $this->storeInStorageControllerTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
                case 'storage_array_hosts':
                    return $this->storeInStorageArrayHostTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
                case 'storage_array_volumes':
                    return $this->storeInStorageArrayVolumeTable($mapping->librenms_field, $transformedValue, $endpointName, $displayKey ?? $key, $mapping);
                    
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
     * Store in storage_arrays table
     */
    protected function storeInStorageArrayTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $arrayName = $this->itemContext['name'] ?? $this->device->hostname;
            
            $array = StorageArray::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'name' => $arrayName,
                ],
                [
                    $field => $value,
                    'last_polled' => now(),
                ]
            );
            
            Log::info("[{$endpointName}] {$displayKey} -> storage_arrays.{$field} (array: {$arrayName}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage_arrays.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in storage_controllers table
     */
    protected function storeInStorageControllerTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $controllerName = $this->itemContext['name'] ?? 'Controller-' . ($this->itemContext['index'] ?? 0);
            
            $controller = StorageController::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'name' => $controllerName,
                ],
                [
                    $field => $value,
                    'last_polled' => now(),
                ]
            );
            
            Log::info("[{$endpointName}] {$displayKey} -> storage_controllers.{$field} (controller: {$controllerName}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage_controllers.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in storage_array_hosts table
     */
    protected function storeInStorageArrayHostTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $hostName = $this->itemContext['name'] ?? 'Host-' . ($this->itemContext['index'] ?? 0);
            
            // Handle JSON fields
            if (in_array($field, ['iqns', 'wwns', 'nqns', 'port_connectivity_details', 'connected_ports', 'mapped_volumes'])) {
                // If value is already an array, keep it; if string, wrap in array
                if (!is_array($value)) {
                    $value = [$value];
                }
            }
            
            $host = StorageArrayHost::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'name' => $hostName,
                ],
                [
                    $field => $value,
                    'last_polled' => now(),
                ]
            );
            
            $displayValue = is_array($value) ? json_encode($value) : $value;
            Log::info("[{$endpointName}] {$displayKey} -> storage_array_hosts.{$field} (host: {$hostName}) = {$displayValue}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage_array_hosts.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in storage_array_volumes table
     */
    protected function storeInStorageArrayVolumeTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $volumeName = $this->itemContext['name'] ?? 'Volume-' . ($this->itemContext['index'] ?? 0);
            
            // Handle JSON fields
            if (in_array($field, ['mapped_hosts'])) {
                if (!is_array($value)) {
                    $value = [$value];
                }
            }
            
            $volume = StorageArrayVolume::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'name' => $volumeName,
                ],
                [
                    $field => $value,
                    'last_polled' => now(),
                ]
            );
            
            $displayValue = is_array($value) ? json_encode($value) : $value;
            Log::info("[{$endpointName}] {$displayKey} -> storage_array_volumes.{$field} (volume: {$volumeName}) = {$displayValue}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage_array_volumes.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in storage table (legacy LibreNMS table)
     */
    protected function storeInStorageTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $storageDescr = $this->extractStorageDescr($displayKey, $mapping);
            
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
            $portName = $this->extractPortName($displayKey, $mapping);
            
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
            if ($field === 'sensor_current' && !is_numeric($value)) {
                Log::debug("[{$endpointName}] Skipping non-numeric sensor value for {$displayKey}: {$value}");
                return false;
            }
            
            $sensorInfo = $this->determineSensorType($field, $unit, $displayKey);
            
            if (!empty($this->itemContext['name'])) {
                $sensorDescr = $this->itemContext['name'] . ' - ' . str_replace('_', ' ', ucwords($displayKey, '_'));
            } else {
                $sensorDescr = str_replace('_', ' ', ucwords($displayKey, '_'));
            }
            $sensorDescr = preg_replace('/\s+/', ' ', $sensorDescr);
            
            $indexBase = !empty($this->itemContext['name']) 
                ? $this->itemContext['name'] . '_' . $displayKey
                : $displayKey . '_' . $endpointName;
            $sensorIndex = abs(crc32($this->device->device_id . '_' . $indexBase));
            
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
            
            $sensor->update(['sensor_current' => $value]);
            
            Log::info("[{$endpointName}] {$displayKey} -> sensors.sensor_current (class: {$sensorInfo['class']}, descr: {$sensorDescr}) = {$value}" . ($unit ? " ({$unit})" : ''));
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store sensor {$field}: " . $e->getMessage());
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
        
        if (strpos($field, 'temp') !== false || strpos($displayKey, 'temperature') !== false || $unit === '°C' || $unit === 'celsius') {
            return ['class' => 'temperature', 'description' => 'Temperature'];
        }
        if (strpos($field, 'voltage') !== false || $unit === 'V' || $unit === 'volts') {
            return ['class' => 'voltage', 'description' => 'Voltage'];
        }
        if (strpos($field, 'fan') !== false || strpos($displayKey, 'fan') !== false || $unit === 'RPM') {
            return ['class' => 'fanspeed', 'description' => 'Fan Speed'];
        }
        if (strpos($field, 'power') !== false || $unit === 'W' || $unit === 'watts') {
            return ['class' => 'power', 'description' => 'Power'];
        }
        if (strpos($field, 'ampere') !== false || $unit === 'A' || $unit === 'amps') {
            return ['class' => 'current', 'description' => 'Current'];
        }
        if (strpos($field, 'percent') !== false || strpos($field, 'usage') !== false || $unit === '%') {
            return ['class' => 'percentage', 'description' => 'Percentage'];
        }
        if (strpos($field, 'count') !== false || strpos($field, 'status') !== false) {
            return ['class' => 'count', 'description' => 'Count'];
        }
        
        return ['class' => 'state', 'description' => 'State'];
    }

    protected function extractStorageDescr(string $displayKey, $mapping = null): string
    {
        if (!empty($this->itemContext['name'])) {
            return substr($this->itemContext['name'], 0, 64);
        }
        if (isset($this->itemContext['type']) && $this->itemContext['type'] === 'aggregated') {
            return 'total-provisioned';
        }
        
        $name = preg_replace('/(space|total|used|free|size|percent|_)+$/', '', $displayKey);
        $name = trim($name, '_');
        
        if (strlen($name) < 3 || in_array($name, ['volume', 'storage', 'disk'])) {
            $name = 'rest-api-storage-' . ($this->itemContext['index'] ?? 'unknown');
        }
        
        return substr(str_replace('_', '-', $name), 0, 64);
    }

    protected function extractPortName(string $displayKey, $mapping = null): string
    {
        if (!empty($this->itemContext['name'])) {
            return substr($this->itemContext['name'], 0, 32);
        }
        
        if (preg_match('/(ct[0-9]+[_\.]eth[0-9]+)/i', $displayKey, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }
        if (preg_match('/(eth[0-9]+)/i', $displayKey, $matches)) {
            return $matches[1];
        }
        if (!empty($this->itemContext['id'])) {
            return substr($this->itemContext['id'], 0, 32);
        }
        
        return substr(str_replace('_', '.', $displayKey), 0, 32);
    }

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

    protected function shouldSkip(string $key): bool
    {
        $cleanKey = strtolower($key);
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

    protected function routeByMapping(string $key, $value, string $mapping, string $resourceType, string $endpointName = 'unknown', string $displayKey = null): bool
    {
        $parts = explode('.', $mapping);
        if (count($parts) < 2) {
            return false;
        }

        if ($parts[0] === 'rrd') {
            $rrdName = $parts[1] ?? $key;
            return $this->storeInRrd($rrdName, $key, $value, $endpointName, $displayKey ?? $key);
        }
        return false;
    }

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

    protected function storeInRrd(string $rrdName, string $key, $value, string $endpointName = 'unknown', string $displayKey = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        try {
            $datastore = app('Datastore');
            $dsName = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $key), 0, 19);
            $rrd_def = RrdDefinition::make()->addDataset($dsName, 'GAUGE', 0, 125000000000);
            
            $datastore->put(
                ['device_id' => $this->device->device_id],
                "rest_api_{$rrdName}",
                ['rrd_def' => $rrd_def, 'rrd_name' => ['rest_api', $rrdName]],
                $value
            );
            
            Log::debug("[{$endpointName}] " . ($displayKey ?? $key) . " -> RRD: rest_api_{$rrdName} = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error storing RRD {$rrdName}: " . $e->getMessage());
            return false;
        }
    }

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
                    'endpoint_name' => $endpointName,
                    'metric_value' => is_array($value) ? json_encode($value) : (string) $value,
                    'last_updated' => now(),
                ]
            );
            
            Log::debug("[{$endpointName}] " . ($displayKey ?? $key) . " -> fallback table ({$resourceType})");
        } catch (\Exception $e) {
            Log::error("Error storing in fallback table: " . $e->getMessage());
        }
    }

    protected function generateRrdName(string $key, string $resourceType): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $key));
        
        if (!str_starts_with($name, $resourceType . '_')) {
            $name = $resourceType . '_' . $name;
        }
        
        return $name;
    }
}
