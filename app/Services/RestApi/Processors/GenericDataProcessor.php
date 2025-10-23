<?php

namespace App\Services\RestApi\Processors;

use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric;
use App\Services\RestApi\Contracts\VendorDataProcessorInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generic REST API Data Processor
 *
 * Handles any REST API endpoint using database-driven mappings.
 * This is the fallback processor that runs when no vendor-specific
 * processor can handle the endpoint.
 *
 * Supports:
 * - JSONPath-based field mapping (from template_response_mapping)
 * - Array data ($.items[*].field patterns)
 * - Single entity data ($.field patterns)
 * - All LibreNMS tables (devices, ports, storage, sensors, entPhysical, etc.)
 * - Custom metrics (rest_api_metrics table)
 */
class GenericDataProcessor implements VendorDataProcessorInterface
{
    /**
     * GenericDataProcessor can handle ANY endpoint as a fallback
     */
    public function canProcess(RestApiEndpoint $endpoint): bool
    {
        return true; // Always returns true - this is the fallback processor
    }

    /**
     * Process endpoint data using mappings from template_response_mapping
     */
    public function process(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void
    {
        // Get mappings from endpoint's template_response_mapping field
        $mappings = $endpoint->template_response_mapping;

        if (empty($mappings) || !is_array($mappings)) {
            Log::debug("No template_response_mapping for endpoint {$endpoint->path}, skipping generic processing", [
                'device_id' => $connection->device_id,
                'endpoint_id' => $endpoint->id,
            ]);
            return;
        }

        // Process mappings by grouping them into complete entities
        $this->processMappings($connection, $endpoint, $mappings, $data);
    }

    public function getPriority(): int
    {
        return 999; // Lowest priority - always runs last as fallback
    }

    public function getVendorName(): string
    {
        return 'generic';
    }

    public function getDescription(): string
    {
        return 'Generic processor that handles standard JSON→database mappings for any REST API';
    }

    /**
     * Process all mappings for an endpoint
     * Groups mappings by array items to preserve entity relationships
     */
    protected function processMappings(RestApiConnection $connection, RestApiEndpoint $endpoint, array $mappings, array $data): void
    {
        // Check if we're dealing with array data ($.items[*].field pattern)
        $hasArrayMappings = false;
        foreach ($mappings as $apiField) {
            if (str_contains($apiField, '[*]')) {
                $hasArrayMappings = true;
                break;
            }
        }

        if ($hasArrayMappings) {
            // Process as array of entities
            $this->processArrayMappings($connection, $endpoint, $mappings, $data);
        } else {
            // Process as single entity (legacy behavior)
            foreach ($mappings as $tableField => $apiField) {
                try {
                    $this->processMapping($connection, $endpoint, $tableField, $apiField, $data);
                } catch (\Throwable $e) {
                    Log::warning("Failed to process mapping {$tableField} <= {$apiField} for {$endpoint->path}: {$e->getMessage()}", [
                        'device_id' => $connection->device_id,
                        'table_field' => $tableField,
                        'api_field' => $apiField,
                    ]);
                }
            }
        }
    }

    /**
     * Process array-based mappings where each item is a complete entity
     * Example: volumes, ports, hardware components
     */
    protected function processArrayMappings(RestApiConnection $connection, RestApiEndpoint $endpoint, array $mappings, array $data): void
    {
        // Extract the base array path (e.g., "$.items" from "$.items[*].field")
        $baseArrayPath = null;
        foreach ($mappings as $apiField) {
            if (preg_match('/^(\$\.[\w.]+)\[\*\]/', $apiField, $matches)) {
                $baseArrayPath = $matches[1];
                break;
            }
        }

        if (!$baseArrayPath) {
            return;
        }

        // Get the array of items
        $items = $this->extractJsonPath($data, $baseArrayPath);
        if (!is_array($items) || empty($items)) {
            return;
        }

        // Process each item as a complete entity
        foreach ($items as $item) {
            $entityData = [];
            $targetTable = null;

            // Extract all mapped fields for this item
            foreach ($mappings as $tableField => $apiField) {
                // Convert array pattern to single item pattern
                // "$.items[*].name" -> "$.name"
                $itemFieldPath = preg_replace('/^\$\.[\w.]+\[\*\]\./', '$.', $apiField);

                $value = $this->extractJsonPath($item, $itemFieldPath);
                if ($value === null) {
                    continue;
                }

                list($table, $field) = $this->parseTableField($tableField);

                if ($targetTable === null) {
                    $targetTable = $table;
                }

                $entityData[$field] = $value;
            }

            // Apply the complete entity
            if (!empty($entityData) && $targetTable) {
                $this->applyEntity($connection->device_id, $targetTable, $entityData, $endpoint);
            }
        }
    }

    /**
     * Process a single mapping
     * tableField format: "table.field" or "table.field[index]"
     * apiField format: "$.path.to.field" or "$.items[*].field"
     */
    protected function processMapping(RestApiConnection $connection, RestApiEndpoint $endpoint, string $tableField, string $apiField, array $data): void
    {
        // Extract value from API response using JSONPath
        $value = $this->extractJsonPath($data, $apiField);

        if ($value === null || (is_array($value) && empty($value))) {
            return;
        }

        // Parse table.field notation
        list($table, $field) = $this->parseTableField($tableField);

        // If value is an array (from wildcard extraction), process each item
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->applyValue($connection->device_id, $table, $field, $item, $endpoint);
            }
        } else {
            $this->applyValue($connection->device_id, $table, $field, $value, $endpoint);
        }
    }

    /**
     * JSONPath parser - extracts values from arrays using JSONPath notation
     * Supports: $.items[*].field, $.items[0].field, $.field.subfield
     */
    protected function extractJsonPath(array $data, string $path): mixed
    {
        // Handle simple direct path
        if (strpos($path, '$') === 0) {
            $path = substr($path, 1);
        }

        if (strpos($path, '.') === 0) {
            $path = substr($path, 1);
        }

        // Handle array wildcard notation like items[*].field
        if (preg_match('/^(\w+)\[\*\]\.(.+)$/', $path, $matches)) {
            $arrayField = $matches[1];
            $subPath = $matches[2];

            if (!isset($data[$arrayField]) || !is_array($data[$arrayField])) {
                return null;
            }

            $results = [];
            foreach ($data[$arrayField] as $item) {
                $value = $this->extractJsonPath($item, '.' . $subPath);
                if ($value !== null) {
                    $results[] = $value;
                }
            }

            return !empty($results) ? $results : null;
        }

        // Handle numeric array index like items[0]
        if (preg_match('/^(\w+)\[(\d+)\](?:\.(.+))?$/', $path, $matches)) {
            $arrayField = $matches[1];
            $index = (int) $matches[2];
            $subPath = $matches[3] ?? null;

            if (!isset($data[$arrayField][$index])) {
                return null;
            }

            $value = $data[$arrayField][$index];

            if ($subPath) {
                return $this->extractJsonPath($value, '.' . $subPath);
            }

            return $value;
        }

        // Handle nested path like field.subfield.nested
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Parse table.field notation
     * Examples: "devices.hostname", "storage.storage_descr", "ports.ifName"
     * If no table prefix is provided, defaults to 'metrics' table (rest_api_metrics)
     */
    protected function parseTableField(string $tableField): array
    {
        $parts = explode('.', $tableField, 2);

        if (count($parts) !== 2) {
            // No table prefix - treat as a metric key and use 'metrics' as the table
            return ['metrics', $tableField];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Apply a complete entity (row) with all its fields
     * This method is shared with vendor-specific processors
     */
    protected function applyEntity(int $deviceId, string $table, array $entityData, RestApiEndpoint $endpoint): void
    {
        \App\Services\RestApi\DataPersistence::applyEntity($deviceId, $table, $entityData, $endpoint);
    }

    /**
     * Apply a single value to a table field
     * This method is shared with vendor-specific processors
     */
    protected function applyValue(int $deviceId, string $table, string $column, mixed $value, RestApiEndpoint $endpoint): void
    {
        \App\Services\RestApi\DataPersistence::applyValue($deviceId, $table, $column, $value, $endpoint);
    }
}
