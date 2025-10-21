<?php

namespace App\RestApi\Discovery;

use Log;

/**
 * API Preview Analyzer
 * 
 * Analyzes API response structure and suggests mappings
 * Used by UI to help admin define mappings
 */
class ApiPreviewAnalyzer
{
    /**
     * Analyze response structure and suggest mappings
     * 
     * @param array $response API response to analyze
     * @param string $endpointPath Endpoint path (for context)
     * @return array Suggestions grouped by table
     */
    public static function suggestMappings(array $response, string $endpointPath = ''): array
    {
        $suggestions = [];

        // Analyze response structure
        $structure = self::analyzeStructure($response);

        // Generate suggestions based on structure
        $suggestions['devices'] = self::suggestDeviceFields($structure, $endpointPath);
        $suggestions['storage'] = self::suggestStorageFields($structure, $endpointPath);
        $suggestions['ports'] = self::suggestPortFields($structure, $endpointPath);
        $suggestions['sensors'] = self::suggestSensorFields($structure, $endpointPath);

        // Remove empty suggestion groups
        $suggestions = array_filter($suggestions);

        return $suggestions;
    }

    /**
     * Analyze response structure (what fields exist, what types)
     */
    protected static function analyzeStructure(array $response): array
    {
        $structure = [];

        foreach ($response as $key => $value) {
            $structure[$key] = [
                'type' => self::getDataType($value),
                'sample' => self::getSample($value),
                'is_array' => is_array($value) && isset($value[0]),
                'is_object' => is_array($value) && !isset($value[0]),
            ];

            // Recurse if nested
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                $structure[$key]['nested'] = self::analyzeStructure($value[0]);
            }
        }

        return $structure;
    }

    /**
     * Suggest device-level fields
     */
    protected static function suggestDeviceFields(array $structure, string $endpoint): array
    {
        $suggestions = [];

        $patterns = [
            'hostname' => ['name', 'device_name', 'array_name', 'system_name'],
            'version' => ['version', 'firmware', 'software_version'],
            'hardware' => ['model', 'product', 'device_model'],
            'serial' => ['serial', 'serial_number', 'device_id', 'id'],
        ];

        foreach ($patterns as $dbField => $fieldNames) {
            foreach ($fieldNames as $name) {
                if (isset($structure[$name])) {
                    $suggestions[] = [
                        'source_field' => '$.' . $name,
                        'target_field' => $dbField,
                        'target_table' => 'devices',
                        'confidence' => 0.95,
                        'reason' => "Field name '$name' matches device field '$dbField'",
                        'sample' => $structure[$name]['sample'],
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Suggest storage/volume fields
     */
    protected static function suggestStorageFields(array $structure, string $endpoint): array
    {
        $suggestions = [];

        // Look for storage-related patterns
        if (self::hasKey($structure, ['name', 'capacity', 'provisioned', 'used'])) {
            $suggestions[] = [
                'source_field' => '$.items[*].name',
                'target_field' => 'storage_descr',
                'target_table' => 'storage',
                'is_identifier' => true,
                'confidence' => 0.90,
                'reason' => 'Detected multi-item storage response with name field',
            ];

            if (isset($structure['capacity']) || isset($structure['provisioned'])) {
                $sizeField = isset($structure['capacity']) ? 'capacity' : 'provisioned';
                $suggestions[] = [
                    'source_field' => '$.items[*].' . $sizeField,
                    'target_field' => 'storage_size',
                    'target_table' => 'storage',
                    'confidence' => 0.85,
                    'reason' => 'Field ' . $sizeField . ' likely represents total storage size',
                    'sample' => $structure[$sizeField]['sample'],
                ];
            }

            if (isset($structure['used']) || isset($structure['physical'])) {
                $usedField = isset($structure['used']) ? 'used' : 'physical';
                $suggestions[] = [
                    'source_field' => '$.items[*].' . $usedField,
                    'target_field' => 'storage_used',
                    'target_table' => 'storage',
                    'confidence' => 0.85,
                    'reason' => 'Field ' . $usedField . ' likely represents used storage',
                    'sample' => $structure[$usedField]['sample'],
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Suggest network interface/port fields
     */
    protected static function suggestPortFields(array $structure, string $endpoint): array
    {
        $suggestions = [];

        // Look for interface-related patterns
        if (self::hasKey($structure, ['name', 'speed', 'status']) || 
            strpos(strtolower($endpoint), 'interface') !== false || 
            strpos(strtolower($endpoint), 'port') !== false) {

            $suggestions[] = [
                'source_field' => '$.items[*].name',
                'target_field' => 'ifName',
                'target_table' => 'ports',
                'is_identifier' => true,
                'confidence' => 0.90,
                'reason' => 'Endpoint appears to be interface/port related',
            ];

            if (isset($structure['speed'])) {
                $suggestions[] = [
                    'source_field' => '$.items[*].speed',
                    'target_field' => 'ifSpeed',
                    'target_table' => 'ports',
                    'confidence' => 0.90,
                    'sample' => $structure['speed']['sample'],
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Suggest sensor fields
     */
    protected static function suggestSensorFields(array $structure, string $endpoint): array
    {
        $suggestions = [];

        $sensorPatterns = [
            'temperature' => ['temperature', 'temp', 'celsius'],
            'voltage' => ['voltage', 'volts'],
            'power' => ['power', 'watts', 'psu'],
            'iops' => ['iops', 'reads_per_sec', 'writes_per_sec'],
            'bandwidth' => ['bandwidth', 'bytes_per_sec', 'throughput'],
            'latency' => ['latency', 'usec', 'microseconds'],
        ];

        foreach ($sensorPatterns as $sensorType => $fieldNames) {
            foreach ($fieldNames as $name) {
                if (isset($structure[$name]) && $structure[$name]['type'] === 'number') {
                    $suggestions[] = [
                        'source_field' => '$.' . $name,
                        'target_field' => $sensorType,
                        'target_table' => 'sensors',
                        'confidence' => 0.85,
                        'reason' => "Field name '$name' matches sensor type '$sensorType'",
                        'sample' => $structure[$name]['sample'],
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Check if structure has any of the given keys
     */
    protected static function hasKey(array $structure, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($structure[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get data type of a value
     */
    protected static function getDataType($value): string
    {
        if (is_numeric($value)) return 'number';
        if (is_bool($value)) return 'boolean';
        if (is_string($value)) return 'string';
        if (is_array($value)) return 'array';
        return 'unknown';
    }

    /**
     * Get sample value (for display)
     */
    protected static function getSample($value)
    {
        if (is_array($value)) {
            if (isset($value[0])) {
                return $value[0]; // First item of array
            }
            return '[object]';
        }

        if (is_string($value) && strlen($value) > 50) {
            return substr($value, 0, 50) . '...';
        }

        return $value;
    }
}
