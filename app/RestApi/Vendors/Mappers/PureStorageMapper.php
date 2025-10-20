<?php

namespace App\RestApi\Vendors\Mappers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\VendorMapperInterface;
use Illuminate\Support\Str;
use Log;

/**
 * Pure Storage REST API Vendor Mapper
 * Handles Pure Storage specific data mapping, validation, and filtering
 */
class PureStorageMapper implements VendorMapperInterface
{
    /**
     * Check if this mapper handles Pure Storage devices
     *
     * @param Device $device
     * @param RestApiEndpoint $endpoint
     * @return bool
     */
    public function canHandle(Device $device, RestApiEndpoint $endpoint): bool
    {
        return $device->os === 'purestorage';
    }

    /**
     * Get Pure Storage specific mapping instructions
     *
     * @return array
     */
    public function getInstructions(): array
    {
        return [
            'volumes' => [
                'description' => 'Storage volumes/LUNs',
                'resource_type' => 'volume',
                'recommended_table' => 'storage',
                'filter_rules' => [
                    'exclude_patterns' => [
                        '/^ITS-RSA-ESXI-/',      // ESXi hosts
                        '/^ALM-C220-ESXI-/',
                        '/^ALMH-C[0-9]S[0-9]+$/',
                        '/^RSA-IAAS-/',
                        '/^RSA-MH-/',           // HyperConverged nodes
                        '/^RSA-PS-/',           // Array itself
                    ],
                ],
                'example_fields' => ['name', 'provisioned', 'space', 'created'],
            ],
            'network-interfaces' => [
                'description' => 'Network interfaces/Ports',
                'resource_type' => 'network-interface',
                'recommended_table' => 'ports',
                'filter_rules' => [
                    'include_patterns' => [
                        '/^ct[0-9]\.eth[0-9]+/',  // Controller interfaces
                        '/^vir[0-9]+/',            // Virtual interfaces
                        '/^replbond/',             // Replication bond
                    ],
                ],
                'example_fields' => ['name', 'enabled', 'eth.address', 'speed'],
            ],
            'hardware' => [
                'description' => 'Hardware components',
                'resource_type' => 'hardware',
                'recommended_table' => 'entPhysical',
                'filter_rules' => [
                    'exclude_patterns' => [
                        '/^CH[0-9]\./',  // Chassis components
                        '/^CT[0-9]\.FAN/',  // Fans
                        '/^CT[0-9]\.TMP/',  // Temperature sensors
                    ],
                ],
            ],
        ];
    }

    /**
     * Analyze API response and recommend field mappings
     *
     * @param array $apiResponse
     * @param RestApiEndpoint $endpoint
     * @return array
     */
    public function getRecommendedMappings(array $apiResponse, RestApiEndpoint $endpoint): array
    {
        $recommendations = [];

        // Analyze first item in response
        $sample = reset($apiResponse['items'] ?? []);
        if (!$sample) {
            return $recommendations;
        }

        // Volume recommendations
        if (Str::contains($endpoint->path, 'volumes')) {
            $recommendations = [
                'name' => [
                    'table' => 'storage',
                    'field' => 'storage_descr',
                    'confidence' => 0.99,
                    'reason' => 'Volume name maps to storage description',
                    'dataType' => 'string',
                ],
                'provisioned' => [
                    'table' => 'storage',
                    'field' => 'storage_size',
                    'confidence' => 0.95,
                    'reason' => 'Provisioned space = total storage size',
                    'dataType' => 'integer',
                ],
                'space.total_used' => [
                    'table' => 'storage',
                    'field' => 'storage_used',
                    'confidence' => 0.95,
                    'reason' => 'Total used space',
                    'dataType' => 'integer',
                ],
            ];
        }

        // Network interface recommendations
        if (Str::contains($endpoint->path, 'network-interface')) {
            $recommendations = [
                'name' => [
                    'table' => 'ports',
                    'field' => 'ifName',
                    'confidence' => 0.99,
                    'reason' => 'Interface name',
                    'dataType' => 'string',
                ],
                'enabled' => [
                    'table' => 'ports',
                    'field' => 'ifAdminStatus',
                    'confidence' => 0.90,
                    'reason' => 'Enabled status = admin status (boolean to int)',
                    'dataType' => 'boolean',
                ],
                'speed' => [
                    'table' => 'ports',
                    'field' => 'ifSpeed',
                    'confidence' => 0.85,
                    'reason' => 'Interface speed in bps',
                    'dataType' => 'integer',
                ],
            ];
        }

        return $recommendations;
    }

    /**
     * Validate if API field can map to database field
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

        // Define compatibility rules
        $compatibilityRules = [
            'storage' => [
                'storage_descr' => ['string'],
                'storage_size' => ['integer', 'float'],
                'storage_used' => ['integer', 'float'],
                'storage_free' => ['integer', 'float'],
                'storage_perc' => ['integer', 'float'],
            ],
            'ports' => [
                'ifName' => ['string'],
                'ifDescr' => ['string'],
                'ifSpeed' => ['integer', 'float'],
                'ifAdminStatus' => ['integer', 'boolean', 'string'],
                'ifOperStatus' => ['string'],
                'ifIndex' => ['integer', 'string'],
            ],
            'entPhysical' => [
                'entPhysicalDescr' => ['string'],
                'entPhysicalName' => ['string'],
                'entPhysicalIndex' => ['integer', 'string'],
            ],
        ];

        $compatible = $compatibilityRules[$table][$field] ?? [];
        $isValid = in_array($apiType, $compatible);

        return [
            'valid' => $isValid,
            'api_type' => $apiType,
            'expected_types' => $compatible,
            'reason' => $isValid
                ? "Type '{$apiType}' is compatible with {$table}.{$field}"
                : "Type '{$apiType}' not compatible with {$table}.{$field}. Expected: " . implode('|', $compatible),
            'sample' => $this->transformValue($apiValue, $apiType, $field),
        ];
    }

    /**
     * Get compatible database fields for a data type
     *
     * @param string $table
     * @param string $dataType
     * @return array
     */
    public function getCompatibleFields(string $table, string $dataType): array
    {
        $fields = [
            'storage' => [
                'integer' => ['storage_size', 'storage_used', 'storage_free', 'storage_perc', 'storage_index'],
                'string' => ['storage_descr', 'storage_type', 'type'],
                'float' => ['storage_size', 'storage_used', 'storage_free', 'storage_perc'],
            ],
            'ports' => [
                'integer' => ['ifSpeed', 'ifIndex', 'ifAdminStatus', 'ifType', 'ifMtu'],
                'string' => ['ifName', 'ifDescr', 'ifOperStatus', 'ifAlias'],
                'boolean' => ['ifAdminStatus'],
                'float' => ['ifSpeed'],
            ],
            'entPhysical' => [
                'integer' => ['entPhysicalIndex', 'entPhysicalContainedIn'],
                'string' => ['entPhysicalDescr', 'entPhysicalName', 'entPhysicalClass'],
            ],
        ];

        if (!isset($fields[$table][$dataType])) {
            return [];
        }

        $fieldList = $fields[$table][$dataType];
        return array_combine($fieldList, $fieldList);
    }

    /**
     * Determine if item should be filtered out
     *
     * @param array $itemContext
     * @param string $resourceType
     * @return bool
     */
    public function shouldFilterItem(array $itemContext, string $resourceType): bool
    {
        if (empty($itemContext['name'])) {
            return false;
        }

        $name = $itemContext['name'];

        // Volumes: exclude hosts and hardware
        if ($resourceType === 'volume') {
            $patterns = [
                '/^ITS-RSA-ESXI-/',
                '/^ALM-C220-ESXI-/',
                '/^ALMH-C[0-9]S[0-9]+$/',
                '/^RSA-IAAS-/',
                '/^RSA-MH-/',
                '/^RSA-PS-/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    Log::debug("PureStorageMapper: Filtering volume: {$name}");
                    return true;
                }
            }

            // Filter zero-provisioned items
            if (isset($itemContext['provisioned']) && $itemContext['provisioned'] == 0) {
                Log::debug("PureStorageMapper: Filtering zero-provisioned volume: {$name}");
                return true;
            }
        }

        // Interfaces: only allow valid interfaces
        if ($resourceType === 'network-interface') {
            $validPatterns = [
                '/^ct[0-9]\.eth[0-9]+/',
                '/^vir[0-9]+/',
                '/^replbond/',
            ];

            $isValid = false;
            foreach ($validPatterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                Log::debug("PureStorageMapper: Filtering invalid interface: {$name}");
                return true;
            }
        }

        // Hardware: exclude certain components
        if ($resourceType === 'hardware') {
            $patterns = [
                '/^CH[0-9]\./',
                '/^CT[0-9]\.FAN/',
                '/^CT[0-9]\.TMP/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    Log::debug("PureStorageMapper: Filtering hardware: {$name}");
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Transform API data before storing
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

            // Apply transformations
            if (Str::endsWith($dbField, '_size') || Str::endsWith($dbField, '_used') || Str::endsWith($dbField, '_free')) {
                // Ensure numeric for storage fields
                $value = intval($value);
            }

            if ($dbField === 'ifAdminStatus' && is_bool($value)) {
                // Convert boolean to int
                $value = $value ? 1 : 0;
            }

            $transformed[$dbField] = $value;
        }

        return $transformed;
    }

    /**
     * Check if metric is vendor-specific complex metric
     *
     * @param string $key
     * @param string $resourceType
     * @return bool
     */
    public function isComplexMetric(string $key, string $resourceType): bool
    {
        $patterns = [
            '/^space_(data_reduction|thin_provisioning|shared|snapshots|unique|virtual)/',
            '/^(data_reduction|total_reduction|thin_provisioning)$/',
            '/^host_(connectivity|iqns|wwns|nqns|connections)/',
            '/^pod_(replication|status)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

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

    /**
     * Transform value for display
     *
     * @param mixed $value
     * @param string $dataType
     * @param string $field
     * @return mixed
     */
    private function transformValue($value, string $dataType, string $field)
    {
        if ($dataType === 'boolean') {
            return $value ? 1 : 0;
        }

        if ($dataType === 'float' && Str::endsWith($field, '_size')) {
            return intval($value);
        }

        return $value;
    }
}
