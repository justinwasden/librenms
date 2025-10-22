<?php

namespace App\RestApi\Storage;

use App\Models\Device;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\RestApi\Utils\JsonPathExtractor;
use Log;

/**
 * Metric Storage Engine - Cleaner implementation
 * 
 * Works with new mapping format that directly maps database.field to response.path
 */
class MetricStorageEngine
{
    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Store metrics from API response using mapper's field mappings
     * 
     * @param array $response Raw API response
     * @param object $endpoint Endpoint object with path
     * @param object $mapper Mapper instance with field mappings
     */
    public function storeFromResponse(array $response, $endpoint, $mapper)
    {
        $config = $mapper->getMappingsForEndpoint($endpoint->path);
        
        if (empty($config) || !isset($config['target_table'])) {
            Log::warning("Invalid config for endpoint: {$endpoint->path}");
            return;
        }

        $targetTable = $config['target_table'];
        $itemIdentifier = $config['item_identifier'] ?? null;
        $fields = $config['fields'] ?? [];

        if (empty($fields)) {
            Log::warning("No fields configured for {$endpoint->path}");
            return;
        }

        // If no item identifier, treat as single record (devices, array performance, space)
        if ($itemIdentifier === null) {
            $this->storeSingleRecord($response, $targetTable, $fields, $endpoint->path, $config, $mapper);
            return;
        }

        // Extract items array and group by identifier
        $items = $response['items'] ?? [];
        if (!is_array($items)) {
            $items = [$response];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Get the identifier value for this item
            $identifier = JsonPathExtractor::extract($item, $itemIdentifier);
            if (!$identifier) {
                Log::debug("No identifier found for item in {$endpoint->path}");
                continue;
            }

            $this->storeGroupedRecord($item, $identifier, $targetTable, $fields, $endpoint->path, $config, $mapper);
        }
    }

    /**
     * Store a single record (for endpoints without items array or single device data)
     */
    protected function storeSingleRecord(array $response, string $targetTable, array $fields, string $endpoint, array $config, $mapper)
    {
        // Handle response that may or may not have items array
        $item = $response['items'][0] ?? $response;

        switch ($targetTable) {
            case 'devices':
                $this->storeDeviceRecord($item, $fields);
                break;
            case 'sensors':
                $this->storeSensorRecord($item, null, $fields, $endpoint, $config, $mapper);
                break;
        }
    }

    /**
     * Store a grouped record (for endpoints with items array)
     */
    protected function storeGroupedRecord(array $item, string $identifier, string $targetTable, array $fields, string $endpoint, array $config, $mapper)
    {
        switch ($targetTable) {
            case 'ports':
                $this->storePortRecord($item, $identifier, $fields);
                break;
            case 'storage':
                $this->storeStorageRecord($item, $identifier, $fields);
                break;
            case 'sensors':
                $this->storeSensorRecord($item, $identifier, $fields, $endpoint, $config, $mapper);
                break;
            case 'links':
                $this->storeLinkRecord($item, $identifier, $fields);
                break;
        }
    }

    /**
     * Store device record
     */
    protected function storeDeviceRecord(array $item, array $fields)
    {
        $data = [];

        foreach ($fields as $dbField => $jsonPath) {
            // Parse database field (e.g., "devices.hostname" -> "hostname")
            $fieldName = $this->parseDbField($dbField, 'devices');
            if (!$fieldName) continue;

            $value = $this->extractValue($item, $jsonPath);
            if ($value !== null) {
                $data[$fieldName] = $value;
            }
        }

        if (!empty($data)) {
            $this->device->update($data);
            Log::info("Device updated: " . json_encode($data));
        }
    }

    /**
     * Store port record
     */
    protected function storePortRecord(array $item, string $identifier, array $fields)
    {
        $data = [];

        foreach ($fields as $dbField => $jsonPath) {
            $fieldName = $this->parseDbField($dbField, 'ports');
            if (!$fieldName) continue;

            $value = $this->extractValue($item, $jsonPath);
            if ($value !== null) {
                $data[$fieldName] = $value;
            }
        }

        if (empty($data)) {
            Log::debug("No data extracted for port: {$identifier}");
            return;
        }

        $port = Port::updateOrCreate(
            ['device_id' => $this->device->device_id, 'ifName' => $identifier],
            $data
        );

        Log::info("Port '{$identifier}' stored");
    }

    /**
     * Store storage record
     */
    protected function storeStorageRecord(array $item, string $identifier, array $fields)
    {
        $data = [];

        foreach ($fields as $dbField => $jsonPath) {
            $fieldName = $this->parseDbField($dbField, 'storage');
            if (!$fieldName) continue;

            $value = $this->extractValue($item, $jsonPath);
            if ($value !== null) {
                $data[$fieldName] = $value;
            }
        }

        if (empty($data)) {
            Log::debug("No data extracted for storage: {$identifier}");
            return;
        }

        $storage = Storage::updateOrCreate(
            ['device_id' => $this->device->device_id, 'storage_descr' => $identifier],
            $data
        );

        Log::info("Storage '{$identifier}' stored");
    }

    /**
     * Store sensor record
     */
    protected function storeSensorRecord(array $item, ?string $identifier, array $fields, string $endpoint, array $config, $mapper)
    {
        $prefix = $config['sensor_prefix'] ?? 'metric';

        foreach ($fields as $dbField => $jsonPath) {
            $fieldName = $this->parseDbField($dbField, 'sensors');
            if (!$fieldName) continue;

            $value = $this->extractValue($item, $jsonPath);
            if ($value === null || !is_numeric($value)) {
                continue;
            }

            // Build sensor description
            $sensorDescr = $identifier
                ? "{$prefix}_{$identifier}_{$fieldName}"
                : "{$prefix}_{$fieldName}";

            // Get sensor class from mapper using the field name
            $sensorClass = $mapper->getSensorClass($endpoint, $fieldName) ?? 'gauge';

            $sensor = Sensor::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'sensor_class' => $sensorClass,
                    'sensor_type' => 'rest-api',
                    'sensor_descr' => $sensorDescr,
                ],
                [
                    'sensor_oid' => "rest-api.{$sensorDescr}",
                    'poller_type' => 'rest-api',
                    'sensor_current' => (float)$value,
                ]
            );

            Log::debug("Sensor '{$sensorDescr}' = {$value}");
        }
    }

    /**
     * Store link record
     */
    protected function storeLinkRecord(array $item, string $identifier, array $fields)
    {
        $data = [];

        foreach ($fields as $dbField => $jsonPath) {
            $fieldName = $this->parseDbField($dbField, 'links');
            if (!$fieldName) continue;

            $value = $this->extractValue($item, $jsonPath);
            if ($value !== null) {
                $data[$fieldName] = $value;
            }
        }

        if (empty($data)) {
            Log::debug("No data extracted for link: {$identifier}");
            return;
        }

        Log::info("Link data: " . json_encode($data));
    }

    /**
     * Extract value from item using JSONPath
     * Handles static values in quotes
     */
    protected function extractValue(array $item, string $jsonPath): mixed
    {
        // Handle static values (quoted strings)
        if (preg_match("/^'(.+)'$/", $jsonPath, $matches)) {
            return $matches[1];
        }

        return JsonPathExtractor::extract($item, $jsonPath);
    }

    /**
     * Parse database field from "table.field" format
     */
    protected function parseDbField(string $dbField, string $expectedTable): ?string
    {
        if (strpos($dbField, '.') === false) {
            return null;
        }

        list($table, $field) = explode('.', $dbField, 2);

        if ($table !== $expectedTable) {
            return null;  // Skip fields for other tables
        }

        return $field;
    }
}
