<?php

namespace App\Services\RestApi;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\Services\RestApi\Vendors\VendorMappingRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class RestApiDataProcessor
{
    protected FieldExtractor $extractor;

    public function __construct(FieldExtractor $extractor)
    {
        $this->extractor = $extractor;
    }

    public function processEndpointResponse(
        RestApiEndpoint $endpoint,
        array $responseData,
        Device $device
    ): array {
        try {
            $mapping = $endpoint->getMappingConfig();

            if (empty($mapping)) {
                Log::warning("No mapping configured for endpoint", [
                    'endpoint_id' => $endpoint->id,
                ]);
                return ['status' => 'no_mapping'];
            }

            // Extract fields using JSONPath or apply vendor mapping
            $fields = $this->extractAndMapFields($responseData, $mapping, $device, $endpoint);

            if (empty($fields)) {
                Log::warning("No fields extracted from response", [
                    'endpoint_id' => $endpoint->id,
                ]);
                return ['status' => 'no_data'];
            }

            // Route to appropriate database table based on resource_type
            $result = $this->routeToTable($endpoint->resource_type, $fields, $device, $endpoint);

            return $result;
        } catch (Exception $e) {
            Log::error("Error processing endpoint response", [
                'endpoint_id' => $endpoint->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function extractAndMapFields(
        array $responseData,
        array $mapping,
        Device $device,
        RestApiEndpoint $endpoint
    ): array {
        // Try vendor-specific mapping first
        $vendorMapping = $this->getVendorMapping($device, $endpoint);
        
        if ($vendorMapping) {
            return $this->applyVendorMapping($vendorMapping, $responseData, $endpoint, $device);
        }

        // Fall back to standard JSONPath extraction
        return $this->extractor->extractAllFields($responseData, $mapping);
    }

    private function getVendorMapping($device, $endpoint)
    {
        // Check if device has custom mapping specified
        $customMapping = DB::table('rest_api_device_mappings')
            ->where('device_id', $device->device_id)
            ->where('endpoint_id', $endpoint->id)
            ->first();

        if ($customMapping && $customMapping->mapping_type === 'custom') {
            return $this->loadCustomMapping($customMapping->mapping_name);
        }

        if ($customMapping && $customMapping->mapping_type === 'vendor') {
            VendorMappingRegistry::initialize();
            $vendorClass = VendorMappingRegistry::get($customMapping->mapping_name);
            
            if ($vendorClass) {
                return new $vendorClass();
            }
        }

        // Auto-detect vendor mapping from device OS
        if ($device->os) {
            VendorMappingRegistry::initialize();
            $vendorClass = VendorMappingRegistry::getForOs($device->os);
            
            if ($vendorClass) {
                return new $vendorClass();
            }
        }

        return null;
    }

    private function applyVendorMapping($vendorMapping, array $responseData, RestApiEndpoint $endpoint, Device $device): array
    {
        $resourceType = $endpoint->resource_type;

        return match($resourceType) {
            'device' => $vendorMapping->mapDevice($responseData, $device) ?? [],
            'port' => $vendorMapping->mapPorts($responseData, $device) ?? [],
            'storage' => $vendorMapping->mapStorage($responseData, $device) ?? [],
            'sensor' => $vendorMapping->mapSensors($responseData, $device) ?? [],
            'custom' => $vendorMapping->mapCustom($responseData, $device) ?? [],
            default => [],
        };
    }

    private function loadCustomMapping(string $name): ?array
    {
        return VendorMappingRegistry::loadCustomMapping($name);
    }

    protected function routeToTable(
        string $resourceType,
        array $fields,
        Device $device,
        RestApiEndpoint $endpoint
    ): array {
        return match($resourceType) {
            'device' => $this->insertDevice($fields, $device),
            'port' => $this->insertPorts($fields, $device),
            'storage' => $this->insertStorage($fields, $device),
            'sensor' => $this->insertSensors($fields, $device),
            'custom' => $this->insertCustom($fields, $device, $endpoint),
            default => ['status' => 'unknown_type', 'type' => $resourceType],
        };
    }

    protected function insertDevice(array $fields, Device $device): array
    {
        try {
            $updates = [];

            $allowedFields = ['version', 'hardware', 'os', 'location', 'sysName', 'serial'];

            foreach ($allowedFields as $field) {
                if (isset($fields[$field])) {
                    $updates[$field] = $fields[$field];
                }
            }

            if (!empty($updates)) {
                $device->update($updates);
                return ['status' => 'success', 'type' => 'device', 'records' => 1];
            }

            return ['status' => 'no_updates'];
        } catch (Exception $e) {
            Log::error("Error inserting device data: {$e->getMessage()}");
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    protected function insertPorts(array $fields, Device $device): array
    {
        try {
            $count = 0;

            // Handle single port or array
            $ports = isset($fields[0]) && is_array($fields[0]) ? $fields : [$fields];

            foreach ($ports as $port) {
                if (!isset($port['ifName'])) {
                    continue;
                }

                DB::table('ports')->updateOrCreate(
                    ['device_id' => $device->device_id, 'ifName' => $port['ifName']],
                    [
                        'ifDescr' => $port['ifDescr'] ?? $port['ifName'],
                        'ifType' => $port['ifType'] ?? 'ethernetCsmacd',
                        'ifSpeed' => $port['ifSpeed'] ?? 0,
                        'ifPhysAddress' => $port['ifPhysAddress'] ?? '',
                        'ifMtu' => $port['ifMtu'] ?? 1500,
                        'ifAdminStatus' => $port['ifAdminStatus'] ?? 1,
                        'ifOperStatus' => $port['ifOperStatus'] ?? 1,
                        'ifAlias' => $port['ifAlias'] ?? '',
                        'ifVlan' => $port['ifVlan'] ?? 0,
                    ]
                );

                if (isset($port['ipv4_address'])) {
                    DB::table('ipv4_addresses')->updateOrCreate(
                        ['device_id' => $device->device_id, 'ipv4_address' => $port['ipv4_address']],
                        [
                            'ipv4_prefixlen' => 24,
                            'ipv4_network' => $this->networkFromIp($port['ipv4_address']),
                            'ipv4_compressed' => 'no',
                            'ipv4_origin' => 'other',
                        ]
                    );
                }

                $count++;
            }

            return ['status' => 'success', 'type' => 'port', 'records' => $count];
        } catch (Exception $e) {
            Log::error("Error inserting port data: {$e->getMessage()}");
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    protected function insertStorage(array $fields, Device $device): array
    {
        try {
            $count = 0;

            // Handle single storage or array
            $storages = isset($fields[0]) && is_array($fields[0]) ? $fields : [$fields];

            foreach ($storages as $storage) {
                if (!isset($storage['storage_descr'])) {
                    continue;
                }

                DB::table('storage')->updateOrCreate(
                    ['device_id' => $device->device_id, 'storage_descr' => $storage['storage_descr']],
                    [
                        'storage_type' => $storage['storage_type'] ?? 'unknown',
                        'storage_size' => $storage['storage_size'] ?? 0,
                        'storage_used' => $storage['storage_used'] ?? 0,
                        'storage_free' => $storage['storage_free'] ?? 0,
                        'storage_perc' => $storage['storage_perc'] ?? 0,
                    ]
                );

                $count++;
            }

            return ['status' => 'success', 'type' => 'storage', 'records' => $count];
        } catch (Exception $e) {
            Log::error("Error inserting storage data: {$e->getMessage()}");
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    protected function insertSensors(array $fields, Device $device): array
    {
        try {
            $count = 0;

            // Handle single sensor or array
            $sensors = isset($fields[0]) && is_array($fields[0]) ? $fields : [$fields];

            foreach ($sensors as $sensor) {
                if (!isset($sensor['sensor_descr'])) {
                    continue;
                }

                DB::table('sensors')->updateOrCreate(
                    [
                        'device_id' => $device->device_id,
                        'sensor_descr' => $sensor['sensor_descr'],
                    ],
                    [
                        'sensor_type' => $sensor['sensor_type'] ?? 'gauge',
                        'sensor_value' => $sensor['sensor_value'] ?? 0,
                        'sensor_class' => $sensor['sensor_class'] ?? 'gauge',
                        'poller_type' => 'api',
                    ]
                );

                $count++;
            }

            return ['status' => 'success', 'type' => 'sensor', 'records' => $count];
        } catch (Exception $e) {
            Log::error("Error inserting sensor data: {$e->getMessage()}");
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    protected function insertCustom(array $fields, Device $device, RestApiEndpoint $endpoint): array
    {
        try {
            DB::table('devices')->where('device_id', $device->device_id)->update([
                'custom_data' => json_encode($fields),
            ]);

            return ['status' => 'success', 'type' => 'custom'];
        } catch (Exception $e) {
            Log::error("Error inserting custom data: {$e->getMessage()}");
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function networkFromIp(string $ip, int $prefixlen = 24): string
    {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts) . '/' . $prefixlen;
        }
        return $ip . '/24';
    }
}
