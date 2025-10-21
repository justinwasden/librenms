<?php

namespace App\RestApi\DataProcessors;

use Illuminate\Support\Str;
use Log;

/**
 * Pure Storage Specific Data Processor
 *
 * Handles Pure Storage specific:
 * - Item filtering (excludes hosts, hardware, zero-provisioned items)
 * - Data transformation (convert types, apply Pure Storage rules)
 * - Complex metric handling (space calculations, ratios)
 * - Validation of Pure Storage data
 */
class PureStorageDataProcessor
{
    /**
     * Determine if item should be filtered out
     *
     * @param array $itemContext
     * @param string $resourceType
     * @return bool
     */
    public static function shouldFilter(array $itemContext, string $resourceType): bool
    {
        if (empty($itemContext['name'])) {
            return false;
        }

        $name = $itemContext['name'];

        // ===== VOLUMES =====
        if ($resourceType === 'volume') {
            // Exclude ESXi hosts
            if (preg_match('/^ITS-RSA-ESXI-/', $name) ||
                preg_match('/^ALM-C220-ESXI-/', $name)) {
                Log::debug("PureStorageDataProcessor: Filtering ESXi volume: {$name}");
                return true;
            }

            // Exclude infrastructure/hyperconverged
            if (preg_match('/^ALMH-C[0-9]S[0-9]+$/', $name) ||
                preg_match('/^RSA-IAAS-/', $name) ||
                preg_match('/^RSA-MH-/', $name) ||
                preg_match('/^RSA-PS-/', $name)) {
                Log::debug("PureStorageDataProcessor: Filtering infrastructure volume: {$name}");
                return true;
            }

            // Filter zero-provisioned items
            if (isset($itemContext['provisioned']) && $itemContext['provisioned'] == 0) {
                Log::debug("PureStorageDataProcessor: Filtering zero-provisioned volume: {$name}");
                return true;
            }
        }

        // ===== NETWORK INTERFACES =====
        if ($resourceType === 'network-interface') {
            // Only allow valid Pure Storage interface names
            $validPatterns = [
                '/^ct[0-9]\.eth[0-9]+/',      // Controller interfaces
                '/^ct[0-9]\.eth[0-9]+\.[0-9]+/',  // VLAN interfaces
                '/^vir[0-9]+/',                // Virtual interfaces
                '/^replbond/',                 // Replication bond
            ];

            $isValid = false;
            foreach ($validPatterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                Log::debug("PureStorageDataProcessor: Filtering invalid interface: {$name}");
                return true;
            }
        }

        // ===== HARDWARE COMPONENTS =====
        if ($resourceType === 'hardware') {
            // Exclude certain hardware types
            $excludePatterns = [
                '/^CH[0-9]\.BAY/',             // Chassis bays
                '/^CH[0-9]\.NVB/',             // NVMe backplanes
                '/^CH[0-9]\.PWR/',             // Power supplies
                '/^CH[0-9]\.TMP/',             // Temperature sensors
                '/^CT[0-9]\.FAN/',             // Fans
                '/^CT[0-9]\.TMP/',             // Controller temps
            ];

            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    Log::debug("PureStorageDataProcessor: Filtering hardware: {$name}");
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Transform Pure Storage data to database format
     *
     * @param array $apiData
     * @param array $mapping
     * @return array
     */
    public static function transform(array $apiData, array $mapping): array
    {
        $transformed = [];

        foreach ($mapping as $apiField => $dbField) {
            $value = self::getNestedValue($apiData, $apiField);

            // Apply Pure Storage specific transformations
            if (Str::endsWith($dbField, '_size') ||
                Str::endsWith($dbField, '_used') ||
                Str::endsWith($dbField, '_free')) {
                // Ensure numeric for storage fields
                $value = intval($value);
            }

            if ($dbField === 'ifAdminStatus' && is_bool($value)) {
                // Convert boolean to int (1 = up, 0 = down)
                $value = $value ? 1 : 0;
            }

            $transformed[$dbField] = $value;
        }

        return $transformed;
    }

    /**
     * Validate Pure Storage data
     *
     * @param string $apiField
     * @param mixed $apiValue
     * @param string $table
     * @param string $field
     * @return array
     */
    public static function validate(string $apiField, $apiValue, string $table, string $field): array
    {
        $apiType = self::getDataType($apiValue);

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
                'ifSpeed' => ['integer', 'float'],
                'ifAdminStatus' => ['integer', 'boolean', 'string'],
            ],
        ];

        $compatible = $compatibilityRules[$table][$field] ?? [];
        $isValid = in_array($apiType, $compatible);

        return [
            'valid' => $isValid,
            'api_type' => $apiType,
            'expected_types' => $compatible,
            'reason' => $isValid
                ? "Type '{$apiType}' compatible with {$table}.{$field}"
                : "Type '{$apiType}' incompatible. Expected: " . implode('|', $compatible),
        ];
    }

    /**
     * Check if metric is complex (needs special handling)
     *
     * @param string $key
     * @return bool
     */
    public static function isComplexMetric(string $key): bool
    {
        $patterns = [
            '/^space_(data_reduction|thin_provisioning|shared|snapshots)/',
            '/^(data_reduction|total_reduction|thin_provisioning)$/',
            '/^host_(connectivity|iqns|wwns)/',
            '/^pod_replication/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get nested value using dot notation
     *
     * @param array $data
     * @param string $path
     * @return mixed
     */
    private static function getNestedValue(array $data, string $path)
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
     * Get PHP data type
     *
     * @param mixed $value
     * @return string
     */
    private static function getDataType($value): string
    {
        if (is_string($value)) return 'string';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'float';
        if (is_bool($value)) return 'boolean';
        if (is_array($value)) return 'array';
        if (is_null($value)) return 'null';
        return 'mixed';
    }
}