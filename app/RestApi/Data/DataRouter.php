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
use App\RestApi\Vendors\VendorMapperFactory;
use App\Models\RestApiEndpoint;
use Log;

/**
 * Generic Data Router for REST API metrics
 * Routes metrics to appropriate database tables based on mappings
 * Delegates vendor-specific logic to VendorMapper implementations
 *
 * REFACTORED: No vendor-specific hardcoding - now fully generic
 */
class DataRouter
{
    protected Device $device;
    protected MappingEngine $mappingEngine;
    protected VendorMapperFactory $vendorMapperFactory;
    protected array $itemContext = [];
    protected $vendorMapper = null;
    protected ?RestApiEndpoint $endpoint = null;

    public function __construct(Device $device, ?RestApiEndpoint $endpoint = null)
    {
        $this->device = $device;
        $this->mappingEngine = new MappingEngine($device);
        $this->vendorMapperFactory = new VendorMapperFactory();
        $this->endpoint = $endpoint;

        // Get vendor-specific mapper
        if ($endpoint) {
            $this->vendorMapper = $this->vendorMapperFactory->getMapper($device, $endpoint);
        }
    }

    /**
     * Route data to appropriate storage location
     * NOW GENERIC - vendor-specific logic delegated to VendorMapper
     *
     * @param array $flattenedData
     * @param string $resourceType
     * @param array $metricMap
     * @param string $endpointName
     * @param array $itemContext
     * @return void
     */
    public function route(
        array $flattenedData,
        string $resourceType,
        array $metricMap = [],
        string $endpointName = 'unknown',
        array $itemContext = []
    ): void
    {
        $this->itemContext = $itemContext;

        $itemName = $itemContext['name'] ?? 'unnamed';
        Log::debug("[{$endpointName}] DataRouter: Processing resource type '{$resourceType}' for item '{$itemName}'");

        // Let vendor mapper decide if this item should be filtered
        if ($this->vendorMapper && $this->vendorMapper->shouldFilterItem($itemContext, $resourceType)) {
            Log::debug("[{$endpointName}] Item filtered by vendor mapper: {$itemName}");
            return;
        }

        // Process each metric
        foreach ($flattenedData as $key => $value) {
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

            // 2. Check if it's a complex metric (vendor-specific)
            if (!$routed && $this->vendorMapper && $this->vendorMapper->isComplexMetric($key, $resourceType)) {
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
     * Uses vendor mapper for validation
     *
     * @param object $mapping
     * @param string $key
     * @param mixed $value
     * @param string $endpointName
     * @return bool
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

            // Let vendor mapper validate if configured
            if ($this->vendorMapper) {
                $validation = $this->vendorMapper->validateMapping(
                    $key,
                    $value,
                    $mapping->librenms_table,
                    $mapping->librenms_field
                );

                if (!$validation['valid']) {
                    Log::warning(
                        "[{$endpointName}] Validation failed for {$key}: {$validation['reason']}"
                    );
                    return false;
                }
            }

            // Update the mapping's last_seen
            $mapping->update([
                'last_matched_device_id' => $this->device->device_id,
                'last_seen_at' => now(),
            ]);

            // Route to appropriate LibreNMS table
            switch ($mapping->librenms_table) {
                case 'storage':
                    return $this->storeInStorageTable(
                        $mapping->librenms_field,
                        $transformedValue,
                        $endpointName,
                        $key,
                        $mapping
                    );

                case 'ports':
                    return $this->storeInPortsTable(
                        $mapping->librenms_field,
                        $transformedValue,
                        $endpointName,
                        $key,
                        $mapping
                    );

                case 'sensors':
                    return $this->storeInSensorsTable(
                        $mapping->librenms_field,
                        $transformedValue,
                        $mapping->unit,
                        $endpointName,
                        $key,
                        $mapping
                    );

                case 'devices':
                    return $this->storeInDevicesTable($mapping->librenms_field, $transformedValue, $endpointName, $key);

                case 'entPhysical':
                    return $this->storeInEntPhysicalTable(
                        $mapping->librenms_field,
                        $transformedValue,
                        $endpointName,
                        $key,
                        $mapping
                    );

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
     *
     * @param string $field
     * @param mixed $value
     * @param string $endpointName
     * @param string $displayKey
     * @param object $mapping
     * @return bool
     */
    protected function storeInStorageTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $storageDescr = $this->itemContext['name'] ?? ($this->itemContext['id'] ?? null);

            if (!$storageDescr) {
                Log::warning("[{$endpointName}] Cannot create storage entry without name or id");
                return false;
            }

            $storage = Storage::where('device_id', $this->device->device_id)
                ->where('storage_descr', $storageDescr)
                ->first();

            if (!$storage) {
                $storage = new Storage();
                $storage->device_id = $this->device->device_id;
                $storage->storage_descr = substr($storageDescr, 0, 255);
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
     *
     * @param string $field
     * @param mixed $value
     * @param string $endpointName
     * @param string $displayKey
     * @param object $mapping
     * @return bool
     */
    protected function storeInPortsTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $portName = $this->itemContext['name'] ?? null;

            if (!$portName) {
                Log::warning("[{$endpointName}] Cannot create port entry without name");
                return false;
            }

            $port = Port::where('device_id', $this->device->device_id)
                ->where('ifName', $portName)
                ->first();

            if (!$port) {
                $port = new Port();
                $port->device_id = $this->device->device_id;
                $port->ifName = substr($portName, 0, 255);
                $port->ifDescr = $portName;
                $port->port_descr_type = 'rest-api';
                $port->ifIndex = abs(crc32($this->device->device_id . '_' . $portName));

                // Set defaults for display
                $port->ifType = 6;  // ethernetCsmacd
                $port->ifOperStatus = 'up';
                $port->ifAdminStatus = 1;
                $port->ifSpeed = 1000000000;  // 1Gbps

                $port->deleted = 0;
                $port->save();
                Log::info("[{$endpointName}] Created new REST API port: {$portName}");
            } else {
                // Ensure port is marked as active if it was previously deleted
                if ($port->deleted == 1) {
                    $port->deleted = 0;
                    $port->save();
                    Log::info("[{$endpointName}] Reactivated previously deleted port: {$portName}");
                }
            }

            // Update field
            if (property_exists($port, $field)) {
                $port->{$field} = $value;
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
     *
     * @param string $field
     * @param mixed $value
     * @param string $endpointName
     * @param string $displayKey
     * @param object $mapping
     * @return bool
     */
    protected function storeInEntPhysicalTable(string $field, $value, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            $entityName = $this->itemContext['name'] ?? ($this->itemContext['id'] ?? null);

            if (!$entityName) {
                Log::warning("[{$endpointName}] Cannot create entPhysical entry without name or id");
                return false;
            }

            $entity = EntPhysical::where('device_id', $this->device->device_id)
                ->where('entPhysicalDescr', $entityName)
                ->first();

            if (!$entity) {
                $entity = new EntPhysical();
                $entity->device_id = $this->device->device_id;
                $entity->entPhysicalDescr = substr($entityName, 0, 255);
                $entity->entPhysicalIndex = abs(crc32($this->device->device_id . '_' . $entityName));
                $entity->entPhysicalClass = $this->itemContext['class'] ?? 'module';
                $entity->entPhysicalName = $entityName;
                $entity->entPhysicalVendorType = 'rest-api';
                $entity->entPhysicalContainedIn = 0;
                $entity->save();
            }

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
     *
     * @param string $field
     * @param mixed $value
     * @param string|null $unit
     * @param string $endpointName
     * @param string $displayKey
     * @param object $mapping
     * @return bool
     */
    protected function storeInSensorsTable(string $field, $value, ?string $unit = null, string $endpointName = 'unknown', string $displayKey = '', $mapping = null): bool
    {
        try {
            if ($field === 'sensor_current' && !is_numeric($value)) {
                Log::debug("[{$endpointName}] Skipping non-numeric sensor value for {$displayKey}: {$value}");
                return false;
            }

            $sensorInfo = $this->determineSensorType($field, $unit, $displayKey);

            if ($value === 0 && in_array($sensorInfo['class'], ['temperature', 'voltage'])) {
                Log::info("[{$endpointName}] Skipping zero value for non-zero sensor type: {$sensorInfo['class']} ({$displayKey})");
                return true;
            }

            if (!empty($this->itemContext['name'])) {
                $sensorDescr = $this->itemContext['name'] . ' - ' . str_replace('_', ' ', ucwords($displayKey, '_'));
            } else {
                $sensorDescr = str_replace('_', ' ', ucwords($displayKey, '_'));
            }
            $sensorDescr = substr(preg_replace('/\s+/', ' ', $sensorDescr), 0, 255);

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
     *
     * @param string $field
     * @param string|null $unit
     * @param string $displayKey
     * @return array
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
     *
     * @param string $field
     * @param mixed $value
     * @param string $endpointName
     * @param string $displayKey
     * @return bool
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
     * Store complex metrics in the JSON metrics table
     *
     * @param string $key
     * @param mixed $value
     * @param string $resourceType
     * @param string $endpointName
     * @return bool
     */
    protected function storeInComplexMetrics(string $key, $value, string $resourceType, string $endpointName): bool
    {
        try {
            StorageArrayMetric::storeMetric(
                $this->device->device_id,
                'complex_metric',
                $key,
                ['value' => $value, 'endpoint' => $endpointName, 'type' => $resourceType]
            );

            Log::debug("[{$endpointName}] {$key} -> storage_array_metrics (complex)");
            return true;
        } catch (\Exception $e) {
            Log::error("[{$endpointName}] Failed to store complex metric {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store in fallback table when no mapping found
     *
     * @param string $key
     * @param mixed $value
     * @param string $resourceType
     * @param string $endpointName
     * @return void
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
                    'metric_value' => is_array($value) ? json_encode($value) : (string)$value,
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
     *
     * @param string $key
     * @return bool
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
