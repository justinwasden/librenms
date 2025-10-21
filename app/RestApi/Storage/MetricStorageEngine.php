<?php

namespace App\RestApi\Storage;

use App\Models\Device;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\RestApi\Utils\JsonPathExtractor;
use Log;

/**
 * Metric Storage Engine
 * 
 * Takes extracted values and stores them to appropriate LibreNMS tables
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
     * This is the new method used by RestApiSimplePoller with vendor mappers
     * 
     * @param array $response Raw API response
     * @param object $endpoint Endpoint object with path
     * @param object $mapper Mapper instance with field mappings
     */
    public function storeFromResponse(array $response, $endpoint, $mapper)
    {
        $mappings = $mapper->getMappingsForEndpoint($endpoint->path);
        $items = $response['items'] ?? [$response];
        
        if (!is_array($items)) {
            $items = [$items];
        }

        foreach ($items as $item) {
            foreach ($mappings as $targetTable => $fieldMappings) {
                $this->storeItemToTable($item, $targetTable, $fieldMappings, $endpoint->path, $mapper);
            }
        }
    }

    /**
     * Store a single API response item to the appropriate LibreNMS table
     * 
     * @param array $item Single item from API response
     * @param string $targetTable Target LibreNMS table name
     * @param array $fieldMappings Field mappings for this table
     * @param string $endpoint Endpoint path for logging
     * @param object $mapper Mapper instance
     */
    protected function storeItemToTable(array $item, string $targetTable, array $fieldMappings, string $endpoint, $mapper)
    {
        Log::debug("Storing to table: {$targetTable} from endpoint: {$endpoint}");

        switch ($targetTable) {
            case 'devices':
                $this->storeDeviceItem($item, $fieldMappings, $endpoint);
                break;
            case 'storage':
                $this->storeStorageItem($item, $fieldMappings, $endpoint);
                break;
            case 'ports':
                $this->storePortItem($item, $fieldMappings, $endpoint);
                break;
            case 'sensors':
                $this->storeSensorItem($item, $fieldMappings, $endpoint, $mapper);
                break;
            case 'links':
                $this->storeLinkItem($item, $fieldMappings, $endpoint);
                break;
            default:
                Log::warning("Unsupported table: {$targetTable}");
        }
    }

    /**
     * Store device-level data from item
     */
    protected function storeDeviceItem(array $item, array $fieldMappings, string $endpoint)
    {
        $data = [];

        foreach ($fieldMappings as $librenmsField => $jsonPath) {
            $value = JsonPathExtractor::extract($item, $jsonPath);

            if ($value === null) {
                continue;
            }

            $data[$librenmsField] = $value;
        }

        if (!empty($data)) {
            $this->device->update($data);
            Log::info("Device updated with " . count($data) . " fields");
        }
    }

    /**
     * Store storage/volume data from item
     */
    protected function storeStorageItem(array $item, array $fieldMappings, string $endpoint)
    {
        $identifier = null;
        $identifierField = null;
        $data = ['storage_type' => 'rest-api'];

        // Extract all fields
        foreach ($fieldMappings as $librenmsField => $jsonPath) {
            $value = JsonPathExtractor::extract($item, $jsonPath);

            if ($value === null) {
                continue;
            }

            // Check if this is the identifier field (usually 'name')
            if (in_array($librenmsField, ['storage_descr', 'name', 'id', 'drive_name'])) {
                if (!$identifier) {
                    $identifier = $value;
                    $identifierField = $librenmsField;
                }
            }

            // Store numeric fields
            if (is_numeric($value)) {
                $data[$librenmsField] = (int)$value;
            } else {
                $data[$librenmsField] = $value;
            }
        }

        if (!$identifier) {
            Log::warning("[{$endpoint}] No identifier found for storage item");
            return;
        }

        $storage = Storage::updateOrCreate(
            ['device_id' => $this->device->device_id, $identifierField => $identifier],
            $data
        );

        Log::info("Storage '{$identifier}' stored to database");
    }

    /**
     * Store port/interface data from item
     */
    protected function storePortItem(array $item, array $fieldMappings, string $endpoint)
    {
        $identifier = null;
        $identifierField = null;
        $data = ['port_descr_type' => 'rest-api', 'ifType' => 6];

        foreach ($fieldMappings as $librenmsField => $jsonPath) {
            $value = JsonPathExtractor::extract($item, $jsonPath);

            if ($value === null) {
                continue;
            }

            // Check for identifier
            if (in_array($librenmsField, ['ifName', 'name', 'ifDescr'])) {
                if (!$identifier) {
                    $identifier = $value;
                    $identifierField = $librenmsField;
                }
            }

            if (is_numeric($value)) {
                $data[$librenmsField] = (int)$value;
            } else {
                $data[$librenmsField] = $value;
            }
        }

        if (!$identifier) {
            Log::warning("[{$endpoint}] No identifier found for port");
            return;
        }

        $port = Port::updateOrCreate(
            ['device_id' => $this->device->device_id, $identifierField => $identifier],
            $data
        );

        Log::info("Port '{$identifier}' stored to database");
    }

    /**
     * Store sensor data from item
     */
    protected function storeSensorItem(array $item, array $fieldMappings, string $endpoint, $mapper)
    {
        foreach ($fieldMappings as $sensorDescr => $jsonPath) {
            $value = JsonPathExtractor::extract($item, $jsonPath);

            if ($value === null) {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            // Extract sensor class from mapper or use gauge as default
            $sensorClass = $mapper->getSensorClass($endpoint, $sensorDescr) ?? 'gauge';

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
                    'sensor_current' => $value,
                ]
            );

            Log::debug("Sensor '{$sensorDescr}' = {$value}");
        }
    }

    /**
     * Store link/connection data from item
     */
    protected function storeLinkItem(array $item, array $fieldMappings, string $endpoint)
    {
        $linkData = [];

        foreach ($fieldMappings as $librenmsField => $jsonPath) {
            $value = JsonPathExtractor::extract($item, $jsonPath);

            if ($value === null) {
                continue;
            }

            $linkData[$librenmsField] = $value;
        }

        if (empty($linkData)) {
            Log::warning("[{$endpoint}] No link data extracted");
            return;
        }

        // For now, just log link data. Full implementation would store to links table
        Log::info("Link data extracted: " . json_encode($linkData));
    }

    /**
     * Store metrics from API response using mappings (legacy method)
     * 
     * @param array $response Raw API response
     * @param \Illuminate\Support\Collection $mappings Grouped by table
     * @param string $endpointName Endpoint name for logging
     */
    public function store(array $response, $mappings, string $endpointName = 'unknown')
    {
        foreach ($mappings as $table => $tableMappings) {
            $this->storeTable($response, $table, $tableMappings, $endpointName);
        }
    }

    /**
     * Store data for a specific table (legacy)
     */
    protected function storeTable(array $response, string $table, $mappings, string $endpoint)
    {
        Log::debug("[{$endpoint}] Storing to table: {$table}");

        switch ($table) {
            case 'devices':
                $this->storeDevices($response, $mappings, $endpoint);
                break;
            case 'storage':
                $this->storeStorage($response, $mappings, $endpoint);
                break;
            case 'ports':
                $this->storePorts($response, $mappings, $endpoint);
                break;
            case 'sensors':
                $this->storeSensors($response, $mappings, $endpoint);
                break;
            default:
                Log::warning("[{$endpoint}] Unsupported table: {$table}");
        }
    }

    /**
     * Store device-level data (legacy)
     */
    protected function storeDevices(array $response, $mappings, string $endpoint)
    {
        $data = [];

        foreach ($mappings as $mapping) {
            $value = JsonPathExtractor::extract($response, $mapping->source_field);

            if ($value === null && $mapping->is_required) {
                Log::warning("[{$endpoint}] Required field missing: {$mapping->source_field}");
                continue;
            }

            $value = $this->applyTransformation($value, $mapping->transformation);

            if ($this->device->hasAttribute($mapping->target_field)) {
                $data[$mapping->target_field] = $value;
                Log::debug("[{$endpoint}] Device.{$mapping->target_field} = {$value}");
            }
        }

        if (!empty($data)) {
            $this->device->update($data);
        }
    }

    /**
     * Store storage/volume data (legacy)
     */
    protected function storeStorage(array $response, $mappings, string $endpoint)
    {
        $identifier = null;
        $identifierField = null;
        $data = [];

        foreach ($mappings as $mapping) {
            $value = JsonPathExtractor::extract($response, $mapping->source_field);

            if ($value === null) {
                if ($mapping->is_required) {
                    Log::warning("[{$endpoint}] Required field missing: {$mapping->source_field}");
                }
                continue;
            }

            $value = $this->applyTransformation($value, $mapping->transformation);

            if ($mapping->is_identifier) {
                $identifier = $value;
                $identifierField = $mapping->target_field;
            }

            $data[$mapping->target_field] = $value;
        }

        if (empty($identifier)) {
            Log::warning("[{$endpoint}] No identifier found for storage entry");
            return;
        }

        // Handle array of values ($.items[*].field)
        if (is_array($identifier)) {
            foreach ($identifier as $idx => $id) {
                $storageData = ['storage_type' => 'rest-api'];

                foreach ($data as $field => $values) {
                    if (is_array($values) && isset($values[$idx])) {
                        $storageData[$field] = $values[$idx];
                    } elseif (!is_array($values)) {
                        $storageData[$field] = $values;
                    }
                }

                $storage = Storage::updateOrCreate(
                    ['device_id' => $this->device->device_id, $identifierField => $id],
                    $storageData
                );

                Log::info("[{$endpoint}] Storage {$id}: updated");
            }
        } else {
            $data['storage_type'] = 'rest-api';
            $storage = Storage::updateOrCreate(
                ['device_id' => $this->device->device_id, $identifierField => $identifier],
                $data
            );

            Log::info("[{$endpoint}] Storage {$identifier}: updated");
        }
    }

    /**
     * Store port/interface data (legacy)
     */
    protected function storePorts(array $response, $mappings, string $endpoint)
    {
        $identifier = null;
        $identifierField = null;
        $data = [];

        foreach ($mappings as $mapping) {
            $value = JsonPathExtractor::extract($response, $mapping->source_field);

            if ($value === null && $mapping->is_required) {
                Log::warning("[{$endpoint}] Required field missing: {$mapping->source_field}");
                continue;
            }

            $value = $this->applyTransformation($value, $mapping->transformation);

            if ($mapping->is_identifier) {
                $identifier = $value;
                $identifierField = $mapping->target_field;
            }

            $data[$mapping->target_field] = $value;
        }

        if (empty($identifier)) {
            Log::warning("[{$endpoint}] No identifier found for port entry");
            return;
        }

        // Handle array of ports
        if (is_array($identifier)) {
            foreach ($identifier as $idx => $id) {
                $portData = ['port_descr_type' => 'rest-api', 'ifType' => 6];

                foreach ($data as $field => $values) {
                    if (is_array($values) && isset($values[$idx])) {
                        $portData[$field] = $values[$idx];
                    } elseif (!is_array($values)) {
                        $portData[$field] = $values;
                    }
                }

                $port = Port::updateOrCreate(
                    ['device_id' => $this->device->device_id, $identifierField => $id],
                    $portData
                );

                Log::info("[{$endpoint}] Port {$id}: updated");
            }
        } else {
            $data['port_descr_type'] = 'rest-api';
            $data['ifType'] = 6;
            $port = Port::updateOrCreate(
                ['device_id' => $this->device->device_id, $identifierField => $identifier],
                $data
            );

            Log::info("[{$endpoint}] Port {$identifier}: updated");
        }
    }

    /**
     * Store sensor data (legacy)
     */
    protected function storeSensors(array $response, $mappings, string $endpoint)
    {
        foreach ($mappings as $mapping) {
            $value = JsonPathExtractor::extract($response, $mapping->source_field);

            if ($value === null) {
                continue;
            }

            $value = $this->applyTransformation($value, $mapping->transformation);

            // Handle array of sensor values
            if (is_array($value)) {
                foreach ($value as $idx => $val) {
                    $this->storeSingleSensor($mapping, $val, "{$idx}", $endpoint);
                }
            } else {
                $this->storeSingleSensor($mapping, $value, "", $endpoint);
            }
        }
    }

    /**
     * Store single sensor value (legacy)
     */
    protected function storeSingleSensor($mapping, $value, string $suffix, string $endpoint)
    {
        if (!is_numeric($value)) {
            return;
        }

        $sensorDescr = $mapping->target_field . ($suffix ? "_{$suffix}" : "");

        $sensor = Sensor::updateOrCreate(
            [
                'device_id' => $this->device->device_id,
                'sensor_class' => 'gauge',
                'sensor_type' => 'rest-api',
                'sensor_descr' => $sensorDescr,
            ],
            [
                'sensor_oid' => 'rest-api.' . $sensorDescr,
                'poller_type' => 'rest-api',
                'sensor_current' => $value,
            ]
        );

        Log::debug("[{$endpoint}] Sensor {$sensorDescr} = {$value}");
    }

    /**
     * Apply data transformation to value
     */
    protected function applyTransformation($value, ?string $transformation)
    {
        if (empty($transformation) || $value === null) {
            return $value;
        }

        // Parse transformation: "divide:1024" or "multiply:8"
        if (preg_match('/(\w+):(.+)/', $transformation, $matches)) {
            $op = $matches[1];
            $operand = $matches[2];

            if (is_numeric($value)) {
                switch ($op) {
                    case 'divide':
                        return $value / $operand;
                    case 'multiply':
                        return $value * $operand;
                    case 'round':
                        return round($value, $operand);
                }
            }
        }

        return $value;
    }
}
