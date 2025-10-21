<?php

namespace App\RestApi\Utils;

/**
 * Simple JSON Flattener - converts nested JSON to dot notation
 * Only used as fallback when template mappings aren't available
 */
class JsonFlattener
{
    /**
     * Flatten nested array/JSON to dot notation
     *
     * @param array $data Input array
     * @param string $prefix Current prefix
     * @return array Flattened key => value pairs
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Skip metadata/pagination fields
            if (self::isMetadata($key)) {
                continue;
            }

            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value) && !empty($value)) {
                // Recurse for nested arrays
                $result = array_merge($result, self::flatten($value, $fullKey));
            } else {
                // Store leaf value
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if key is metadata that should be skipped
     */
    protected static function isMetadata(string $key): bool
    {
        $metadataPatterns = [
            'continuation_token',
            'more_items_remaining',
            'total_item_count',
            'items_count',
        ];

        return in_array(strtolower($key), $metadataPatterns);
    }
}
