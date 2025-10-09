<?php

namespace App\RestApi\Utils;

class JsonFlattener
{
    /**
     * Flatten a nested JSON array into a single-level associative array
     * 
     * @param array $data The nested array to flatten
     * @param string $prefix Prefix for keys (used in recursion)
     * @param string $separator Separator between key parts
     * @return array Flattened array
     */
    public static function flatten(array $data, string $prefix = '', string $separator = '_'): array
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            // Build the new key
            $newKey = $prefix ? $prefix . $separator . $key : $key;
            
            if (is_array($value)) {
                if (self::isAssociative($value)) {
                    // Recursively flatten associative arrays
                    $result = array_merge($result, self::flatten($value, $newKey, $separator));
                } else {
                    // For numeric arrays, store count and optionally the JSON
                    $result[$newKey . '_count'] = count($value);
                    
                    // If array contains simple values, enumerate them
                    if (!empty($value) && !is_array($value[0])) {
                        foreach ($value as $index => $item) {
                            $result[$newKey . '_' . $index] = $item;
                        }
                    }
                }
            } elseif (is_bool($value)) {
                // Convert booleans to integers for RRD storage
                $result[$newKey] = $value ? 1 : 0;
            } elseif (is_null($value)) {
                // Store null as 0 for numeric fields
                $result[$newKey] = 0;
            } else {
                // Store the value directly
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Flatten with a custom mapping/transformation
     * 
     * @param array $data The nested array to flatten
     * @param array $mapping Mapping configuration (jsonpath => metric_name)
     * @return array Mapped metrics
     */
    public static function flattenWithMapping(array $data, array $mapping): array
    {
        $result = [];
        
        foreach ($mapping as $jsonPath => $metricName) {
            $value = self::extractByPath($data, $jsonPath);
            
            if ($value !== null) {
                $result[$metricName] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Extract value from nested array using dot notation path
     * 
     * @param array $data The array to extract from
     * @param string $path Dot notation path (e.g., 'metrics.cpu.usage')
     * @return mixed The extracted value or null if not found
     */
    protected static function extractByPath(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Check if an array is associative
     * 
     * @param array $arr The array to check
     * @return bool True if associative, false if numeric
     */
    protected static function isAssociative(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }
        
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
