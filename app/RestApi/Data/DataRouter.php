<?php

namespace App\RestApi\Data;

use App\Models\Device;
use App\Models\RestApiMetric;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\Models\StorageArrayMetric;
use App\Models\EntPhysical;
use App\RestApi\Mapping\MappingEngine;
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
        $this->itemContext = $itemContext;

        foreach ($flattenedData as $key => $value) {
            // Skip pagination metadata
            if ($this->shouldSkip($key)) {
                continue;
            }

            $routed = false;

            // 1. Try intelligent mapping using MappingEngine
            $mapping = $this->mappingEngine->findMapping($key, $resourceType);
            if ($mapping && $mapping->enabled) {
                Log::debug("✓ [{$endpointName}] {$key} -> {$mapping->librenms_table}.{$mapping->librenms_field}");
                $routed = $this->storeUsingMapping($mapping, $key, $value, $endpointName);
            }

            // 2. Check if it's Pure Storage-specific complex metric (space accounting, data reduction, etc.)
            if (!$routed && $this->isPureStorageComplexMetric($key, $resourceType)) {
                $routed = $this->storeInComplexMetrics($key, $value, $resourceType, $endpointName);
            }

            // 3. Fallback: store in rest_api_metrics table
            if (!$routed) {
                $this->storeInFallbackTable($key, $value, $resourceType, $endpointName);
            }
        }
    }

    /**
     * Store data using a MetricFieldMapping
     */
    protected function storeUsingMapping($mapping, string $key, $value, string $endpointName = 'unknown'): bool
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
                    return $this->storeInStorageTable($mapping->librenms_field, $transformedValue, $endpointName, $key, $mapping);

                case 'ports':
                    return $this->storeInPortsTable($mapping->librenms_field, $transformedValue, $endpointName, $key, $mapping);

                case 'sensors':
                    // Pass unit from mapping to storeInSensorsTable
                    return $this->storeInSensorsTable($mapping->librenms_field, $transformedValue, $mapping->unit, $endpointName, $key, $mapping);

                case 'devices':
                    return $this->storeInDevicesTable($mapping->librenms_field, $transformedValue, $endpointName, $key);

                case 'entPhysical':
                    return $this->storeInEntPhysicalTable($mapping->librenms_field, $transformedValue, $endpointName, $key, $mapping);

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
     * Store in storage table (volumes/LUNs)
     */
    protected function storeInStorageTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            // Use item context (e.g., volume name) or device_id if array-level capacity
            $storageDescr = $this->itemContext['name'] ?? 'rest-api-storage-' . ($this->itemContext['index'] ?? 0);

            $storage = Storage::where('device_id', $this->device->device_id)
                ->where('storage_descr', $storageDescr)
                ->first();

            if (!$storage) {
                $storage = new Storage();
                $storage->device_id = $this->device->device_id;
                $storage->storage_descr = substr($storageDescr, 0, 64);
                $storage->storage_index = (string)abs(crc32($this->device->device_id . '_' . $storageDescr));
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

            // Calculate percentage if we have size and used
            if ($storage->storage_size > 0 && $storage->storage_used > 0) {
                $storage->storage_perc = round(($storage->storage_used / $storage->storage_size) * 100, 2);
                $storage->storage_free = $storage->storage_size - $storage->storage_used;
                $storage->save();
            }

            Log::info("[{$endpointName}] {$displayKey} -> storage.{$field} (descr: {$storageDescr}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in storage.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in ports table (network interfaces)
     */
    protected function storeInPortsTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $portName = $this->itemContext['name'] ?? 'rest-api-port-' . ($this->itemContext['index'] ?? 0);

            $port = Port::where('device_id', $this->device->device_id)
                ->where('ifName', $portName)
                ->first();

            if (!$port) {
                $port = new Port();
                $port->device_id = $this->device->device_id;
                $port->ifName = substr($portName, 0, 32);
                $port->ifDescr =  $portName;
                $port->port_descr_type = 'rest-api';
                $port->ifIndex = abs(crc32($this->device->device_id . '_' . $portName));
                $port->save();
            }

            // Manually set the field and save to avoid $fillable restrictions.
						if (property_exists($port, $field)) {
						    $port->{$field} = $transformedValue;
						    $port->save();
						}

            Log::info("[{$endpointName}] {$displayKey} -> ports.{$field} (port: {$portName}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in ports.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in entPhysical table (hardware/controllers)
     */
    protected function storeInEntPhysicalTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $entityName = $this->itemContext['name'] ?? 'rest-api-entity-' . ($this->itemContext['index'] ?? 0);

            $entity = EntPhysical::where('device_id', $this->device->device_id)
                ->where('entPhysicalDescr', $entityName)
                ->first();

            if (!$entity) {
                $entity = new EntPhysical();
                $entity->device_id = $this->device->device_id;
                $entity->entPhysicalDescr = substr($entityName, 0, 64);
                $entity->entPhysicalIndex = abs(crc32($this->device->device_id . '_' . $entityName));
                $entity->entPhysicalClass = $this->itemContext['class'] ?? 'module'; // Use module as default class for controllers
                $entity->entPhysicalName = $entityName;
                $entity->entPhysicalVendorType = 'rest-api';
                $entity->entPhysicalContainedIn = 0; // Assume top level unless context provides parent
                $entity->save();
            }

            // Only update if value is non-null or non-empty for structural fields
            if (!empty($value) || $field === 'entPhysicalOperStatus' || $field === 'entPhysicalAdminStatus') {
                $entity->update([$field => $value]);
            }

            Log::info("[{$endpointName}] {$displayKey} -> entPhysical.{$field} (entity: {$entityName}) = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store in entPhysical.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in sensors table (performance metrics, hardware sensors)
     */
    protected function storeInSensorsTable(string $field, $value, ?string $unit = null, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            if ($field === 'sensor_current' && !is_numeric($value)) {
                Log::debug("[{$endpointName}] Skipping non-numeric sensor value for {$displayKey}: {$value}");
                return false;
            }

            $sensorInfo = $this->determineSensorType($field, $unit, $displayKey);

            // --- NEW: Skip storing sensors with a value of 0 for specific classes ---
            if ($value === 0 && in_array($sensorInfo['class'], ['temperature', 'voltage'])) {
                Log::info("[{$endpointName}] Skipping zero value for non-zero sensor type: {$sensorInfo['class']} ({$displayKey})");
                return true; // Return true as if handled, but don't store it
            }
            // --- END NEW ---

            // Create descriptive sensor name
            if (!empty($this->itemContext['name'])) {
                $sensorDescr = $this->itemContext['name'] . ' - ' . str_replace('_', ' ', ucwords($displayKey, '_'));
            } else {
                $sensorDescr = str_replace('_', ' ', ucwords($displayKey, '_'));
            }
            $sensorDescr = substr(preg_replace('/\s+/', ' ', $sensorDescr), 0, 64);

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

                // Set limits based on sensor class
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

        if (strpos($displayKey, 'temp') !== false || $unit === 'celsius' || $unit === '°C') {
            return ['class' => 'temperature', 'description' => 'Temperature'];
        }
        if (strpos($displayKey, 'voltage') !== false || $unit === 'volts' || $unit === 'V') {
            return ['class' => 'voltage', 'description' => 'Voltage'];
        }
        if (strpos($displayKey, 'fan') !== false || $unit === 'rpm') {
            return ['class' => 'fanspeed', 'description' => 'Fan Speed'];
        }
        if (strpos($displayKey, 'power') !== false || $unit === 'watts' || $unit === 'W') {
            return ['class' => 'power', 'description' => 'Power'];
        }
        if (strpos($displayKey, 'iops') !== false || strpos($displayKey, '_per_sec') !== false) {
            return ['class' => 'count', 'description' => 'IOPS'];
        }
        if (strpos($displayKey, 'bandwidth') !== false || strpos($displayKey, 'bytes_per_sec') !== false || $unit === 'bps' || $unit === 'bytes/sec') {
            return ['class' => 'count', 'description' => 'Bandwidth'];
        }
        if (strpos($displayKey, 'latency') !== false || strpos($displayKey, 'usec') !== false || $unit === 'microseconds') {
            return ['class' => 'count', 'description' => 'Latency'];
        }
        if (strpos($displayKey, 'percent') !== false || $unit === '%' || $unit === 'ratio') {
            return ['class' => 'percentage', 'description' => 'Percentage'];
        }

        return ['class' => 'count', 'description' => 'Count'];
    }

    /**
     * Store in devices table (array-level info)
     */
    protected function storeInDevicesTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = ''): bool
    {
        try {
            $this->device->update([$field => $value]);
            Log::info("[{$endpointName}] {$displayKey} -> devices.{$field} = {$value}");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to update devices.{$field}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if this is a Pure Storage complex metric that needs special handling
     */
    protected function isPureStorageComplexMetric(string $key, string $resourceType): bool
    {
        $complexPatterns = [
            '/^space_(data_reduction|thin_provisioning|shared|snapshots|unique|virtual)/',
            '/^(data_reduction|total_reduction|thin_provisioning)$/',
            '/^host_(connectivity|iqns|wwns|nqns|connections)/',
            '/^pod_(replication|status)/',
        ];

        foreach ($complexPatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store complex Pure Storage metrics in the JSON metrics table
     */
    protected function storeInComplexMetrics(string $key, $value, string $resourceType, string $endpointName): bool
    {
        try {
            // Determine metric type based on key
            $metricType = 'space_accounting';
            if (strpos($key, 'data_reduction') !== false || strpos($key, 'total_reduction') !== false) {
                $metricType = 'data_reduction';
            } elseif (strpos($key, 'host_') === 0) {
                $metricType = 'host_connectivity';
            } elseif (strpos($key, 'pod_') === 0) {
                $metricType = 'replication';
            }

            // Note: StorageArrayMetric is a custom model, used here as fallback for complex/custom metrics
            StorageArrayMetric::storeMetric(
                $this->device->device_id,
                $metricType,
                $key,
                ['value' => $value, 'endpoint' => $endpointName]
            );

            Log::debug("[{$endpointName}] {$key} -> storage_array_metrics ({$metricType})");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store complex metric {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in fallback table when no mapping found
     */
    protected function storeInFallbackTable(string $key, $value, string $resourceType, string $endpointName = 'unknown'): void
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

            Log::debug("[{$endpointName}] {$key} -> fallback table ({$resourceType})");
        } catch (\Exception $e) {
            Log::error("Error storing in fallback table: " . $e->getMessage());
        }
    }

    /**
     * Check if key should be skipped (pagination metadata)
     */
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
}
