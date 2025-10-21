<?php

namespace App\RestApi\Vendors;

use App\Models\Device;
use App\Models\RestApiEndpoint;

/**
 * Interface for vendor-specific REST API data mapping
 * Implementations handle vendor-specific filtering, validation, and transformation
 */
interface VendorMapperInterface
{
    /**
     * Check if this mapper can handle the device/endpoint combination
     *
     * @param Device $device
     * @param RestApiEndpoint $endpoint
     * @return bool
     */
    public function canHandle(Device $device, RestApiEndpoint $endpoint): bool;

    /**
     * Get vendor-specific mapping instructions
     * Returns information about how to handle this vendor's data
     *
     * @return array
     */
    public function getInstructions(): array;

    /**
     * Analyze API response and recommend field mappings
     * Provides intelligent suggestions based on endpoint and data
     *
     * @param array $apiResponse
     * @param RestApiEndpoint $endpoint
     * @return array ['api_field' => ['table' => 'ports', 'field' => 'ifName', 'confidence' => 0.95, 'reason' => '...']]
     */
    public function getRecommendedMappings(array $apiResponse, RestApiEndpoint $endpoint): array;

    /**
     * Validate if API field can map to database field
     * Checks data type compatibility and vendor-specific rules
     *
     * @param string $apiField The API field name
     * @param mixed $apiValue The API field value
     * @param string $table Target database table
     * @param string $field Target database field
     * @return array ['valid' => bool, 'api_type' => 'string', 'expected_types' => ['integer'], 'reason' => '...', 'sample' => 'transformed value']
     */
    public function validateMapping(
        string $apiField,
        $apiValue,
        string $table,
        string $field
    ): array;

    /**
     * Get compatible database fields for a given data type
     * Suggests which fields can accept a particular data type
     *
     * @param string $table Target database table
     * @param string $dataType Data type ('string', 'integer', 'float', etc.)
     * @return array ['field_name' => 'field_type', ...]
     */
    public function getCompatibleFields(string $table, string $dataType): array;

    /**
     * Determine if an item should be filtered out from results
     * e.g., exclude ESXi hosts from volume results
     *
     * @param array $itemContext Item metadata (name, id, provisioned, etc.)
     * @param string $resourceType Resource type (volume, port, sensor, etc.)
     * @return bool True if item should be filtered (excluded), false to keep it
     */
    public function shouldFilterItem(array $itemContext, string $resourceType): bool;

    /**
     * Transform/normalize API data before storing
     * Applies vendor-specific transformations
     *
     * @param array $apiData
     * @param array $mapping Mapping configuration
     * @return array Transformed data
     */
    public function transform(array $apiData, array $mapping): array;

    /**
     * Check if a metric is vendor-specific complex metric
     * e.g., space accounting, data reduction metrics
     *
     * @param string $key Metric key
     * @param string $resourceType Resource type
     * @return bool
     */
    public function isComplexMetric(string $key, string $resourceType): bool;
}
