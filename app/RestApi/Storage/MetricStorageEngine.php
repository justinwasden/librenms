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
     * Store metrics from API response using mappings
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
     * Store data for a specific table
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
     * Store device-level data
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
     * Store storage/volume data
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
     * Store port/interface data
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
     * Store sensor data
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
     * Store single sensor value
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
