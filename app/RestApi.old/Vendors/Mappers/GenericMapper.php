<?php

namespace App\RestApi\Vendors\Mappers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\VendorMapperInterface;
use Illuminate\Support\Str;
use Log;

/**
 * Generic REST API Vendor Mapper
 * Fallback mapper for any vendor not explicitly supported
 * Provides basic functionality without vendor-specific optimizations
 */
class GenericMapper implements VendorMapperInterface
{
    /**
     * Generic mapper handles all devices
     *
     * @param Device $device
     * @param RestApiEndpoint $endpoint
     * @return bool
     */
    public function canHandle(Device $device, RestApiEndpoint $endpoint): bool
    {
        return true;  // Catch-all
    }

    /**
     * Get generic mapping instructions
     *
     * @return array
     */
    public function getInstructions(): array
    {
        return [
            'generic' => [
                'description' => 'Generic REST API endpoint',
                'resource_type' => 'custom',
                'filter_rules' => [
                    'exclude_patterns' => [],
                ],
            ],
        ];
    }

    /**
     * Try to provide basic recommendations based on endpoint structure
     *
     * @param array $apiResponse
     * @param RestApiEndpoint $endpoint
     * @return array
     */
    public function getRecommendedMappings(array $apiResponse, RestApiEndpoint $endpoint): array
    {
        $recommendations = [];

        // Analyze first item in response
        $items = $apiResponse['items'] ?? [];
        $sample = reset($items);
        if (!$sample) {
            return $recommendations;
        }

        // Simple heuristics
        if (Str::contains($endpoint->path, 'volume') || Str::contains($endpoint->path, 'storage')) {
            // Likely a storage endpoint
            if (isset($sample['name']) && isset($sample['provisioned'])) {
                $recommendations['name'] = [
                    'table' => 'storage',
                    'field' => 'storage_descr',
                    'confidence' => 0.70,
                    'reason' => 'Heuristic: "name" field for storage description',
                ];
            }
        } elseif (Str::contains($endpoint->path, 'interface') || Str::contains($endpoint->path, 'port')) {
            // Likely an interface endpoint
            if (isset($sample['name'])) {
                $recommendations['name'] = [
                    'table' => 'ports',
                    'field' => 'ifName',
                    'confidence' => 0.75,
                    'reason' => 'Heuristic: "name" field for interface name',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generic validation - very permissive
     *
     * @param string $apiField
     * @param mixed $apiValue
     * @param string $table
     * @param string $field
     * @return array
     */
    public function validateMapping(
        string $apiField,
        $apiValue,
        string $table,
        string $field
    ): array
    {
        $apiType = $this->getDataType($apiValue);

        // Generic mapper is permissive but warns about obvious incompatibilities
        $warnings = [];

        if ($apiType === 'array' && !Str::contains($field, 'data')) {
            $warnings[] = "Array type may not map well to '{$field}'";
        }

        return [
            'valid' => true,
            'api_type' => $apiType,
            'expected_types' => ['*'],  // Accept anything
            'reason' => 'Generic mapper: No validation rules. Test thoroughly!',
            'warnings' => $warnings,
            'sample' => $apiValue,
        ];
    }

    /**
     * Generic field compatibility
     *
     * @param string $table
     * @param string $dataType
     * @return array
     */
    public function getCompatibleFields(string $table, string $dataType): array
    {
        // Return common fields for the table
        $commonFields = [
            'storage' => ['storage_descr', 'storage_size', 'storage_used', 'storage_free', 'storage_type'],
            'ports' => ['ifName', 'ifDescr', 'ifSpeed', 'ifAdminStatus', 'ifOperStatus'],
            'sensors' => ['sensor_descr', 'sensor_current'],
            'devices' => ['hostname', 'sysDescr', 'hardware', 'version'],
        ];

        return array_combine(
            $commonFields[$table] ?? [],
            $commonFields[$table] ?? []
        );
    }

    /**
     * No filtering in generic mapper
     *
     * @param array $itemContext
     * @param string $resourceType
     * @return bool
     */
    public function shouldFilterItem(array $itemContext, string $resourceType): bool
    {
        return false;  // Don't filter anything
    }

    /**
     * Minimal transformation
     *
     * @param array $apiData
     * @param array $mapping
     * @return array
     */
    public function transform(array $apiData, array $mapping): array
    {
        $transformed = [];

        foreach ($mapping as $apiField => $dbField) {
            $value = $this->getNestedValue($apiData, $apiField);
            $transformed[$dbField] = $value;
        }

        return $transformed;
    }

    /**
     * Generic mapper has no complex metrics
     *
     * @param string $key
     * @param string $resourceType
     * @return bool
     */
    public function isComplexMetric(string $key, string $resourceType): bool
    {
        return false;
    }

    /**
     * Get data type of a value
     *
     * @param mixed $value
     * @return string
     */
    private function getDataType($value): string
    {
        if (is_string($value)) {
            return 'string';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_array($value)) {
            return 'array';
        }
        if (is_null($value)) {
            return 'null';
        }

        return 'mixed';
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $data
     * @param string $path
     * @return mixed
     */
    private function getNestedValue(array $data, string $path)
    {
        $parts = explode('.', $path);
        $value = $data;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return null;
            }
        }

        return $value;
    }
}
