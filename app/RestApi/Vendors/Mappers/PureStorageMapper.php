<?php

namespace App\RestApi\Vendors\Mappers;

use App\RestApi\Vendors\VendorMapperInterface;
use Illuminate\Support\Facades\Log;

/**
 * PureStorageMapper - Clean Implementation
 *
 * Handles Pure Storage FlashArray REST API 2.26
 * Supports both relative (/arrays) and full (/api/2.26/arrays) endpoint paths
 */
class PureStorageMapper implements VendorMapperInterface
{
    public function getVendorName(): string
    {
        return 'Pure Storage FlashArray (API Token Login)';
    }

    public function getOsPatterns(): array
    {
        return [
            'Purity//FA*',
            'Purity*',
            'Pure*',
        ];
    }

    public function getDescription(): string
    {
        return 'Pure Storage FlashArray REST API 2.26 - Automatic configuration';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * All endpoint mappings
     * Key: relative path (without /api/VERSION)
     * Value: configuration array
     */
    public function getMappings(): array
    {
        return [
            '/arrays' => [
                'target_table' => 'devices',
                'item_identifier' => null,
                'fields' => [
                    'devices.hostname' => '$.items[0].name',
                    'devices.sysName' => '$.items[0].name',
                    'devices.version' => '$.items[0].version',
                    'devices.os' => '$.items[0].os',
                    'devices.hardware' => '$.items[0].model',
                    'devices.serial' => '$.items[0].id',
                ]
            ],
            '/network-interfaces' => [
                'target_table' => 'ports',
                'item_identifier' => '$.items[*].name',
                'fields' => [
                    'ports.ifName' => '$.name',
                    'ports.ifDescr' => '$.services[0]',
                    'ports.ifType' => '$.interface_type',
                    'ports.ifSpeed' => '$.speed',
                    'ports.ifPhysAddress' => '$.eth.mac_address',
                    'ports.ifAdminStatus' => '$.enabled',
                    'ports.ifOperStatus' => '$.enabled',
                    'ports.ifMtu' => '$.eth.mtu',
                ]
            ],
            '/network-interfaces/performance' => [
                'target_table' => 'ports',
                'item_identifier' => '$.items[*].name',
                'fields' => [
                    'ports.ifInOctets' => '$.eth.received_bytes_per_sec',
                    'ports.ifOutOctets' => '$.eth.transmitted_bytes_per_sec',
                    'ports.ifInUcastPkts' => '$.eth.received_packets_per_sec',
                    'ports.ifOutUcastPkts' => '$.eth.transmitted_packets_per_sec',
                ]
            ],
            '/volumes' => [
                'target_table' => 'storage',
                'item_identifier' => '$.items[*].name',
                'fields' => [
                    'storage.storage_descr' => '$.name',
                    'storage.storage_size' => '$.space.total_provisioned',
                    'storage.storage_used' => '$.space.total_physical',
                ]
            ],
            '/drives' => [
                'target_table' => 'storage',
                'item_identifier' => '$.items[*].name',
                'fields' => [
                    'storage.storage_descr' => '$.name',
                    'storage.storage_size' => '$.capacity',
                ]
            ],
            '/arrays/performance' => [
                'target_table' => 'sensors',
                'item_identifier' => null,
                'sensor_prefix' => 'array',
                'fields' => [
                    'sensors.read_throughput' => '$.items[0].read_bytes_per_sec',
                    'sensors.write_throughput' => '$.items[0].write_bytes_per_sec',
                    'sensors.reads_per_sec' => '$.items[0].reads_per_sec',
                    'sensors.writes_per_sec' => '$.items[0].writes_per_sec',
                ]
            ],
            '/volumes/performance' => [
                'target_table' => 'sensors',
                'item_identifier' => '$.items[*].name',
                'sensor_prefix' => 'volume',
                'fields' => [
                    'sensors.read_throughput' => '$.read_bytes_per_sec',
                    'sensors.write_throughput' => '$.write_bytes_per_sec',
                    'sensors.reads_per_sec' => '$.reads_per_sec',
                    'sensors.writes_per_sec' => '$.writes_per_sec',
                ]
            ],
            '/controllers' => [
                'target_table' => 'sensors',
                'item_identifier' => '$.items[*].name',
                'sensor_prefix' => 'controller',
                'fields' => [
                    'sensors.status' => '$.status',
                ]
            ],
            '/hardware' => [
                'target_table' => 'sensors',
                'item_identifier' => '$.items[*].name',
                'sensor_prefix' => 'hardware',
                'fields' => [
                    'sensors.temperature' => '$.temperature',
                    'sensors.voltage' => '$.voltage',
                    'sensors.status' => '$.status',
                ]
            ],
            '/network-interfaces/port-details' => [
                'target_table' => 'sensors',
                'item_identifier' => '$.items[*].name',
                'sensor_prefix' => 'transceiver',
                'fields' => [
                    'sensors.temperature' => '$.temperature[0].measurement',
                    'sensors.voltage' => '$.voltage[0].measurement',
                    'sensors.tx_power' => '$.tx_power[0].measurement',
                    'sensors.rx_power' => '$.rx_power[0].measurement',
                ]
            ],
            '/array-connections' => [
                'target_table' => 'links',
                'item_identifier' => '$.items[*].name',
                'fields' => [
                    'links.local_port' => '$.local_port',
                    'links.remote_port' => '$.remote_port',
                    'links.remote_hostname' => '$.name',
                ]
            ],
            '/space' => [
                'target_table' => 'sensors',
                'item_identifier' => null,
                'sensor_prefix' => 'space',
                'fields' => [
                    'sensors.total_provisioned' => '$.total_provisioned',
                    'sensors.total_used' => '$.total_used',
                ]
            ],
        ];
    }

    /**
     * Get mapping for endpoint
     * Handles both /arrays and /api/2.26/arrays formats
     */
    public function getMappingsForEndpoint(string $endpoint): array
    {
        $allMappings = $this->getMappings();
        
        // Try exact match first
        if (isset($allMappings[$endpoint])) {
            Log::debug("PureStorageMapper: Found exact match for {$endpoint}");
            return $allMappings[$endpoint];
        }
        
        // Extract clean endpoint path from full API path
        // /api/2.26/arrays -> /arrays
        $cleanEndpoint = $this->cleanEndpointPath($endpoint);
        
        if ($cleanEndpoint !== $endpoint && isset($allMappings[$cleanEndpoint])) {
            Log::debug("PureStorageMapper: Matched {$endpoint} to {$cleanEndpoint}");
            return $allMappings[$cleanEndpoint];
        }
        
        Log::warning("PureStorageMapper: No mapping found for endpoint: {$endpoint}");
        return [];
    }

    /**
     * Clean endpoint path - remove API version prefix
     * /api/2.26/arrays -> /arrays
     * /api/2.26/volumes/performance -> /volumes/performance
     */
    private function cleanEndpointPath(string $endpoint): string
    {
        // Match /api/X.X/endpoint -> /endpoint
        if (preg_match('#^/api/[\d.]+(.*)$#', $endpoint, $matches)) {
            return $matches[1] ?: '/';
        }
        
        return $endpoint;
    }

    public function getTargetTableForEndpoint(string $endpoint): string
    {
        $config = $this->getMappingsForEndpoint($endpoint);
        return $config['target_table'] ?? 'sensors';
    }

    public function transformValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    public function isValidMapping(string $endpoint, string $field, string $targetTable): bool
    {
        return true;
    }

    /**
     * Get sensor class for field
     * Maps field names to LibreNMS sensor classes
     */
    public function getSensorClass(string $endpoint, string $sensorDescr): ?string
    {
        $classMap = [
            'read_throughput' => 'bitrate',
            'write_throughput' => 'bitrate',
            'reads_per_sec' => 'count',
            'writes_per_sec' => 'count',
            'read_latency' => 'delay',
            'write_latency' => 'delay',
            'temperature' => 'temperature',
            'voltage' => 'voltage',
            'tx_power' => 'power',
            'rx_power' => 'power',
            'status' => 'state',
            'total_provisioned' => 'bitrate',
            'total_used' => 'bitrate',
        ];

        return $classMap[$sensorDescr] ?? 'gauge';
    }

    public function getSensorDescription(string $endpoint, string $apiField): string
    {
        return $apiField;
    }
}
