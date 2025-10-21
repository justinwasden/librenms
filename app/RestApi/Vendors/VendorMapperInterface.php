<?php

namespace App\RestApi\Vendors;

/**
 * VendorMapperInterface
 *
 * Abstract interface for vendor-specific REST API field mappings.
 * Each vendor (Pure Storage, Cisco, Fortinet, etc.) can implement
 * this interface to provide custom mapping logic.
 */
interface VendorMapperInterface
{
    /**
     * Get the vendor name this mapper handles
     * Examples: "Pure Storage", "Cisco", "Fortinet"
     */
    public function getVendorName(): string;

    /**
     * Get vendor OS patterns this mapper handles
     * Examples: ["Purity//FA", "IOS XE", "FortiOS"]
     * Used to auto-detect which mapper to use
     */
    public function getOsPatterns(): array;

    /**
     * Get all available mappings for this vendor
     * Returns array of endpoint => field mappings
     * 
     * @return array Format:
     * [
     *   '/arrays' => [
     *     'hostname' => '$.items[0].name',
     *     'version' => '$.items[0].version',
     *     ...
     *   ],
     *   '/volumes' => [...],
     * ]
     */
    public function getMappings(): array;

    /**
     * Get mappings for a specific endpoint
     * 
     * @param string $endpoint API endpoint path (e.g., '/arrays')
     * @return array Field mappings for this endpoint
     */
    public function getMappingsForEndpoint(string $endpoint): array;

    /**
     * Get recommended target table for an endpoint
     * 
     * @param string $endpoint API endpoint path
     * @return string Target table: 'devices', 'ports', 'storage', 'sensors', 'links', 'custom'
     */
    public function getTargetTableForEndpoint(string $endpoint): string;

    /**
     * Transform/normalize a value from the API before storing
     * Optional - used for data type conversions, calculations, etc.
     * 
     * @param string $field Field name
     * @param mixed $value Raw value from API
     * @return mixed Transformed value
     */
    public function transformValue(string $field, mixed $value): mixed;

    /**
     * Validate if a field mapping is valid for this vendor
     * 
     * @param string $endpoint API endpoint
     * @param string $field Field name
     * @param string $targetTable Target LibreNMS table
     * @return bool True if mapping is valid
     */
    public function isValidMapping(string $endpoint, string $field, string $targetTable): bool;

    /**
     * Get human-readable description of this mapper
     */
    public function getDescription(): string;

    /**
     * Get sensor class for a specific sensor
     * Optional - used for determining sensor type (gauge, temperature, power, etc.)
     * 
     * @param string $endpoint API endpoint path
     * @param string $sensorDescr Sensor description/name
     * @return string|null Sensor class (gauge, temperature, power, etc.) or null for default
     */
    public function getSensorClass(string $endpoint, string $sensorDescr): ?string;

    /**
     * Get sensor description from API field name
     * Optional - used for generating human-readable sensor descriptions
     * 
     * @param string $endpoint API endpoint path
     * @param string $apiField API field name
     * @return string Readable sensor description
     */
    public function getSensorDescription(string $endpoint, string $apiField): string;
}
