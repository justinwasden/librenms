<?php

namespace App\RestApi\Services;

use App\Models\RestApiDeviceTemplate;
use App\RestApi\Vendors\VendorMapperRegistry;
use App\RestApi\Vendors\Mappers\GenericMapper;

/**
 * MapperSelectionService
 *
 * Handles selection and retrieval of appropriate vendor mapper
 * based on device OS, explicit user selection, or user-defined mappings
 */
class MapperSelectionService
{
    /**
     * Get the best mapper for a device
     *
     * Selection priority:
     * 1. User-selected mapper (if explicitly chosen)
     * 2. Auto-detected from device OS
     * 3. Device-specific custom mappings
     * 4. Generic/fallback mapper
     *
     * @param RestApiDeviceTemplate $deviceTemplate
     * @return array ['mapper' => VendorMapperInterface, 'source' => string, 'mapper_name' => string]
     */
    public static function selectMapper(RestApiDeviceTemplate $deviceTemplate): array
    {
        // 1. Check for explicit user selection
        if ($deviceTemplate->mapper_name) {
            $mapper = VendorMapperRegistry::getMapper($deviceTemplate->mapper_name);
            if ($mapper) {
                return [
                    'mapper' => $mapper,
                    'source' => 'user_selected',
                    'mapper_name' => $deviceTemplate->mapper_name,
                ];
            }
        }

        // 2. Try to auto-detect from device OS
        $device = $deviceTemplate->device;
        if ($device && $device->os) {
            $mapper = VendorMapperRegistry::getMapperByOsPattern($device->os);
            if ($mapper) {
                return [
                    'mapper' => $mapper,
                    'source' => 'auto_detected',
                    'os_pattern' => $device->os,
                    'mapper_name' => $mapper->getVendorName(),
                ];
            }
        }

        // 3. Check for device-specific custom mappings
        if ($deviceTemplate->custom_mappings) {
            $customConfig = json_decode($deviceTemplate->custom_mappings, true);
            $mapper = new GenericMapper($customConfig);

            return [
                'mapper' => $mapper,
                'source' => 'custom_device',
                'mapper_name' => $deviceTemplate->custom_mapping_name ?? 'Custom Device Mapping',
            ];
        }

        // 4. Check for template mappings from template_response_mapping
        if ($deviceTemplate->template && $deviceTemplate->template->template_data) {
            $templateData = $deviceTemplate->template->template_data;
            $mappings = [];
            
            // Extract mappings from each connection's endpoints
            if (isset($templateData['connections'])) {
                foreach ($templateData['connections'] as $connection) {
                    if (isset($connection['endpoints'])) {
                        foreach ($connection['endpoints'] as $endpoint) {
                            $path = $endpoint['path'] ?? null;
                            $metricMap = $endpoint['metric_map'] ?? null;
                            if ($path && $metricMap) {
                                $mappings[$path] = $metricMap;
                            }
                        }
                    }
                }
            }
            
            if (!empty($mappings)) {
                $mapper = new GenericMapper([
                    'vendor_name' => $deviceTemplate->template->vendor,
                    'mappings' => $mappings,
                ]);

                return [
                    'mapper' => $mapper,
                    'source' => 'template',
                    'mapper_name' => $deviceTemplate->template->name,
                ];
            }
        }

        // 5. Return generic fallback
        $mapper = new GenericMapper([
            'vendor_name' => 'Generic',
            'mappings' => [],
        ]);

        return [
            'mapper' => $mapper,
            'source' => 'fallback',
            'mapper_name' => 'Generic',
        ];
    }

    /**
     * Get all available mappers for UI selection
     *
     * @return array Array of mapper options
     */
    public static function getAvailableMappers(): array
    {
        $mappers = [];

        // Add all vendor mappers
        foreach (VendorMapperRegistry::getAllMappers() as $mapper) {
            $mappers[] = [
                'name' => $mapper->getVendorName(),
                'type' => 'vendor',
                'description' => $mapper->getDescription(),
                'version' => $mapper->getVersion(),
            ];
        }

        // Add custom mappers from database
        $customMappings = RestApiDeviceTemplate::whereNotNull('custom_mappings')
            ->distinct('custom_mapping_name')
            ->pluck('custom_mapping_name')
            ->filter()
            ->toArray();

        foreach ($customMappings as $name) {
            $mappers[] = [
                'name' => $name,
                'type' => 'custom',
                'description' => "Custom mapping: $name",
            ];
        }

        return $mappers;
    }

    /**
     * Get endpoints available for a selected mapper
     *
     * @param string $mapperName Mapper name
     * @return array Array of endpoint paths
     */
    public static function getMapperEndpoints(string $mapperName): array
    {
        $mapper = VendorMapperRegistry::getMapper($mapperName);

        if (! $mapper) {
            return [];
        }

        return array_keys($mapper->getMappings());
    }

    /**
     * Get field mappings for a specific endpoint
     *
     * @param string $mapperName Mapper name
     * @param string $endpoint Endpoint path
     * @return array Field mapping configuration
     */
    public static function getEndpointMappings(string $mapperName, string $endpoint): array
    {
        $mapper = VendorMapperRegistry::getMapper($mapperName);

        if (! $mapper) {
            return [];
        }

        return $mapper->getMappingsForEndpoint($endpoint);
    }

    /**
     * Save custom mappings for a device
     *
     * @param RestApiDeviceTemplate $deviceTemplate
     * @param array $mappings Endpoint => field mappings array
     * @param string $name Custom mapping name
     * @return bool
     */
    public static function saveCustomMappings(RestApiDeviceTemplate $deviceTemplate, array $mappings, string $name): bool
    {
        $config = [
            'vendor_name' => $name,
            'mappings' => $mappings,
            'endpoint_targets' => self::inferTargetTables($mappings),
            'created_at' => now()->toIso8601String(),
        ];

        $deviceTemplate->custom_mapping_name = $name;
        $deviceTemplate->custom_mappings = json_encode($config);
        $deviceTemplate->mapper_name = null; // Clear vendor selection if custom is set

        return $deviceTemplate->save();
    }

    /**
     * Infer target tables for endpoints based on field names
     *
     * @param array $mappings
     * @return array Endpoint => target table mapping
     */
    private static function inferTargetTables(array $mappings): array
    {
        $targets = [];

        foreach ($mappings as $endpoint => $fields) {
            // Try to infer from field names
            $fieldNames = implode(' ', array_keys($fields));

            if (preg_match('/(hostname|version|hardware|serial|os)/i', $fieldNames)) {
                $targets[$endpoint] = 'devices';
            } elseif (preg_match('/(ifName|ifSpeed|ifType|ifAlias)/i', $fieldNames)) {
                $targets[$endpoint] = 'ports';
            } elseif (preg_match('/(storage_|capacity|provisioned|drive)/i', $fieldNames)) {
                $targets[$endpoint] = 'storage';
            } else {
                $targets[$endpoint] = 'sensors';
            }
        }

        return $targets;
    }
}
