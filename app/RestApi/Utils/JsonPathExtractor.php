<?php

namespace App\RestApi\Utils;

/**
 * JSONPath Extractor - Extract values from nested arrays using JSONPath syntax
 * 
 * Supports:
 * - $.field.nested.value - nested field access
 * - $.items[0].field - array index access
 * - $.items[*].field - all items in array
 */
class JsonPathExtractor
{
    /**
     * Extract value(s) from data using JSONPath
     * 
     * @param array $data Source data
     * @param string $path JSONPath expression
     * @return mixed Single value or array of values
     */
    public static function extract(array $data, string $path)
    {
        $path = trim($path);
        if (strpos($path, '$.') !== 0) {
            $path = '$.' . $path;
        }

        // Handle wildcard arrays
        if (strpos($path, '[*]') !== false) {
            return self::extractMultiple($data, $path);
        }

        return self::extractSingle($data, $path);
    }

    /**
     * Extract single value
     */
    protected static function extractSingle(array $data, string $path)
    {
        $path = ltrim($path, '$.');
        if (empty($path)) {
            return $data;
        }

        $segments = preg_split('/[\.\[\]]/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }
            $current = $current[$segment] ?? null;
            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    /**
     * Extract multiple values from wildcard path
     */
    protected static function extractMultiple(array $data, string $path)
    {
        if (!preg_match('/\$\.(.*?)\[\*\]\.(.*)/', $path, $matches)) {
            return [];
        }

        $arrayPath = $matches[1];
        $fieldPath = $matches[2];

        $array = self::extractSingle($data, '$.' . $arrayPath);
        if (!is_array($array)) {
            return [];
        }

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
}
