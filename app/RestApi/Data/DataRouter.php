<?php

namespace App\RestApi\Data;

use App\Models\Device;
use App\Models\Storage;
use App\Models\Port;
use App\Models\Sensor;
use App\RestApi\Utils\JsonPathExtractor;
use Log;

/**
 * Clean Data Router - Uses template mappings only
 * No parsing, no matching, no fallbacks - direct path from template to database
 */
class DataRouter
{
    protected Device $device;
    protected ?array $templateMappings = null;

    public function __construct(Device $device, ?array $templateMappings = null)
    {
        $this->device = $device;
        $this->templateMappings = $templateMappings;
    }

    /**
     * Route metrics directly to database using template mappings
     *
     * @param array $rawResponse Raw API response
     * @param array $mappings Template mappings (table => [field => JSONPath])
     * @param string $endpointName Endpoint name for logging
     * @return void
     */
    public function routeByTemplate(array $rawResponse, array $mappings, string $endpointName = 'unknown'): void
    {
        if (empty($mappings)) {
            Log::warning("[{$endpointName}] No mappings defined for endpoint");
            return;
        }

        Log::debug("[{$endpointName}] Routing {" . count($mappings) . "} tables");

        // Process each table type
        foreach ($mappings as $table => $fieldMappings) {
            if (!is_array($fieldMappings)) {
                continue;
            }

            $this->routeTable($rawResponse, $table, $fieldMappings, $endpointName);
        }
    }

    /**
     * Route metrics to a specific table
     *
     * @param array $rawResponse Raw response
     * @param string $table Target table name
     * @param array $fieldMappings Field => JSONPath mappings
     * @param string $endpointName Endpoint name for logging
     */
    protected function routeTable(array $rawResponse, string $table, array $fieldMappings, string $endpointName): void
    {
        Log::debug("[{$endpointName}] Processing table: {$table}");

        switch ($table) {
            case 'devices':
                $this->storeDevices($rawResponse, $fieldMappings, $endpointName);
                break;
            case 'storage':
                $this->storeStorage($rawResponse, $fieldMappings, $endpointName);
                break;
            case 'ports':
                $this->storePorts($rawResponse, $fieldMappings, $endpointName);
                break;
            case 'sensors':
                $this->storeSensors($rawResponse, $fieldMappings, $endpointName);
                break;
            default:
                Log::warning("[{$endpointName}] Unsupported table: {$table}");
        }
    }

    /**
     * Store device-level data
     */
    protected function storeDevices(array $response, array $mappings, string $endpoint): void
    {
        $data = JsonPathExtractor::extractMappings($response, $mappings);
        
        if (empty($data)) {
            Log::debug("[{$endpoint}] No device data to store");
            return;
        }

        $updateData = [];
        foreach ($data as $field => $value) {
            if ($value !== null && $this->device->hasAttribute($field)) {
                $updateData[$field] = $value;
            }
        }

        if (!empty($updateData)) {
            $this->device->update($updateData);
            Log::info("[{$endpoint}] Updated device: " . implode(', ', array_keys($updateData)));
        }
    }

    /**
     * Store storage/volume data (handles arrays)
     */
    protected function storeStorage(array $response, array $mappings, string $endpoint): void
    {
        // Extract all field values
        $extractedData = JsonPathExtractor::extractMappings($response, $mappings);
        
        if (empty($extractedData)) {
            Log::debug("[{$endpoint}] No storage data to store");
            return;
        }

        // If storage_descr is an array, treat each item separately
        if (isset($extractedData['storage_descr']) && is_array($extractedData['storage_descr'])) {
            $names = $extractedData['storage_descr'];
            $storageSizes = is_array($extractedData['storage_size'] ?? null) ? $extractedData['storage_size'] : [];
            $storageUsed = is_array($extractedData['storage_used'] ?? null) ? $extractedData['storage_used'] : [];

            foreach ($names as $index => $name) {
                $storage = Storage::firstOrCreate(
                    ['device_id' => $this->device->device_id, 'storage_descr' => $name],
                    [
                        'storage_type' => 'rest-api',
                        'storage_index' => abs(crc32($this->device->device_id . $name)),
                        'storage_size' => $storageSizes[$index] ?? 0,
                        'storage_used' => $storageUsed[$index] ?? 0,
                        'storage_free' => 0,
                        'storage_perc' => 0,
                    ]
                );

                // Update if values exist
                $updateData = [];
                if (isset($storageSizes[$index])) $updateData['storage_size'] = $storageSizes[$index];
                if (isset($storageUsed[$index])) $updateData['storage_used'] = $storageUsed[$index];

                if (!empty($updateData)) {
                    // Recalculate percentage
                    $storage->update($updateData);
                    if ($storage->storage_size > 0) {
                        $storage->storage_perc = round(($storage->storage_used / $storage->storage_size) * 100, 2);
                        $storage->storage_free = $storage->storage_size - $storage->storage_used;
                        $storage->save();
                    }
                }
            }

            Log::info("[{$endpoint}] Stored " . count($names) . " storage items");
        } else {
            // Single storage item
            $storage = Storage::firstOrCreate(
                ['device_id' => $this->device->device_id, 'storage_descr' => $extractedData['storage_descr'] ?? 'array'],
                ['storage_type' => 'rest-api', 'storage_index' => abs(crc32($this->device->device_id . 'array'))]
            );

            $updateData = [];
            if (isset($extractedData['storage_size'])) $updateData['storage_size'] = $extractedData['storage_size'];
            if (isset($extractedData['storage_used'])) $updateData['storage_used'] = $extractedData['storage_used'];

            if (!empty($updateData)) {
                $storage->update($updateData);
                if ($storage->storage_size > 0) {
                    $storage->storage_perc = round(($storage->storage_used / $storage->storage_size) * 100, 2);
                    $storage->storage_free = $storage->storage_size - $storage->storage_used;
                    $storage->save();
                }
            }

            Log::info("[{$endpoint}] Stored storage: " . ($extractedData['storage_descr'] ?? 'unknown'));
        }
    }

    /**
     * Store network interface/port data
     */
    protected function storePorts(array $response, array $mappings, string $endpoint): void
    {
        $extractedData = JsonPathExtractor::extractMappings($response, $mappings);
        
        if (empty($extractedData)) {
            Log::debug("[{$endpoint}] No port data to store");
            return;
        }

        // Handle array of ports
        if (isset($extractedData['ifName']) && is_array($extractedData['ifName'])) {
            $names = $extractedData['ifName'];
            $speeds = is_array($extractedData['ifSpeed'] ?? null) ? $extractedData['ifSpeed'] : [];

            foreach ($names as $index => $name) {
                $port = Port::firstOrCreate(
                    ['device_id' => $this->device->device_id, 'ifName' => $name],
                    [
                        'ifDescr' => $name,
                        'ifType' => 6,
                        'ifSpeed' => $speeds[$index] ?? 1000000000,
                        'ifOperStatus' => 'up',
                        'ifAdminStatus' => 1,
                        'port_descr_type' => 'rest-api',
                        'ifIndex' => abs(crc32($this->device->device_id . $name)),
                    ]
                );

                if (isset($speeds[$index])) {
                    $port->update(['ifSpeed' => $speeds[$index]]);
                }
            }

            Log::info("[{$endpoint}] Stored " . count($names) . " ports");
        } else {
            // Single port
            $name = $extractedData['ifName'] ?? 'eth0';
            Port::firstOrCreate(
                ['device_id' => $this->device->device_id, 'ifName' => $name],
                [
                    'ifDescr' => $name,
                    'ifType' => 6,
                    'ifSpeed' => $extractedData['ifSpeed'] ?? 1000000000,
                    'ifOperStatus' => 'up',
                    'ifAdminStatus' => 1,
                    'port_descr_type' => 'rest-api',
                    'ifIndex' => abs(crc32($this->device->device_id . $name)),
                ]
            );

            Log::info("[{$endpoint}] Stored port: {$name}");
        }
    }

    /**
     * Store sensor data
     */
    protected function storeSensors(array $response, array $mappings, string $endpoint): void
    {
        $extractedData = JsonPathExtractor::extractMappings($response, $mappings);
        
        if (empty($extractedData)) {
            Log::debug("[{$endpoint}] No sensor data to store");
            return;
        }

        // Each mapping entry should have class and field
        foreach ($mappings as $sensorName => $config) {
            if (!is_array($config) || !isset($config['class'], $config['field'])) {
                continue;
            }

            $value = JsonPathExtractor::extract($response, $config['field']);
            $class = $config['class'] ?? 'gauge';

            if ($value === null) {
                continue;
            }

            // Handle array of values
            if (is_array($value)) {
                foreach ($value as $idx => $val) {
                    $this->storeSingleSensor($sensorName . "_" . $idx, $class, $val, $endpoint);
                }
            } else {
                $this->storeSingleSensor($sensorName, $class, $value, $endpoint);
            }
        }
    }

    /**
     * Store a single sensor
     */
    protected function storeSingleSensor(string $name, string $class, $value, string $endpoint): void
    {
        if (!is_numeric($value)) {
            return;
        }

        $sensor = Sensor::firstOrCreate(
            [
                'device_id' => $this->device->device_id,
                'sensor_class' => $class,
                'sensor_type' => 'rest-api',
                'sensor_descr' => $name,
            ],
            [
                'sensor_index' => abs(crc32($this->device->device_id . $name)),
                'sensor_oid' => 'rest-api.' . $name,
                'poller_type' => 'rest-api',
                'sensor_current' => $value,
            ]
        );

        $sensor->update(['sensor_current' => $value]);
        
        Log::debug("[{$endpoint}] Sensor {$name} = {$value}");
    }
}
