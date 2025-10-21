<?php

namespace App\RestApi\Utils;

use Log;

/**
 * Simple JSONPath extractor for REST API responses
 * Supports basic JSONPath syntax: $.path.to.field and $.items[*].field
 */
class JsonPathExtractor
{
    /**
     * Extract value(s) from array using JSONPath
     *
     * @param array $data Source data
     * @param string $path JSONPath expression (e.g., "$.items[*].name", "$.config.version")
     * @return mixed Single value or array of values
     */
    public static function extract(array $data, string $path)
    {
        // Normalize path
        $path = trim($path);
        if (strpos($path, '$.') !== 0) {
            $path = '$.' . $path;
        }

        // Handle array wildcard paths ($.items[*].field)
        if (strpos($path, '[*]') !== false) {
            return self::extractMultiple($data, $path);
        }

        // Handle single value paths ($.items[0].field or $.version)
        return self::extractSingle($data, $path);
    }

    /**
     * Extract single value from path
     *
     * @param array $data
     * @param string $path JSONPath
     * @return mixed
     */
    protected static function extractSingle(array $data, string $path)
    {
        // Remove leading $
        $path = ltrim($path, '$.');
        
        if (empty($path)) {
            return $data;
        }

        // Split path into segments
        $segments = preg_split('/[\.\[\]]/', $path, -1, PREG_SPLIT_NO_EMPTY);
        
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }
            
            // Handle array index
            if (is_numeric($segment)) {
                $current = $current[$segment] ?? null;
            } else {
                $current = $current[$segment] ?? null;
            }
            
            if ($current === null) {
                return null;
            }
        }
        
        return $current;
    }

    /**
     * Extract multiple values from array wildcard path
     *
     * @param array $data
     * @param string $path JSONPath with [*]
     * @return array
     */
    protected static function extractMultiple(array $data, string $path)
    {
        // Find the array access point and field path
        if (!preg_match('/\$\.(.*?)\[\*\]\.(.*)/', $path, $matches)) {
            return [];
        }

        $arrayPath = $matches[1];
        $fieldPath = $matches[2];

        // Get the array
        $array = self::extractSingle($data, '$.' . $arrayPath);
        
        if (!is_array($array)) {
            return [];
        }

        // Extract field from each item
        $results = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $value = self::extractSingle($item, '$.' . $fieldPath);
                if ($value !== null) {
                    $results[] = $value;
                }
            }
        }

        return $results;
    }

    /**
     * Extract and flatten all mappings from response
     *
     * @param array $data Response data
     * @param array $mappings Mapping definition with JSONPath keys
     * @return array Extracted and flattened data
     */
    public static function extractMappings(array $data, array $mappings): array
    {
        $extracted = [];

        foreach ($mappings as $field => $path) {
            if (is_array($path)) {
                continue; // Skip nested arrays
            }

            $value = self::extract($data, $path);
            
            if (is_array($value)) {
                // If we got multiple values, store as array
                if (count($value) === 1) {
                    $extracted[$field] = $value[0];
                } else {
                    $extracted[$field] = $value;
                }
            } else {
                $extracted[$field] = $value;
            }
        }

        return $extracted;
    }
}
