<?php

namespace App\RestApi\Parsers;

use Log;

/**
 * Parser for PureStorage FlashArray API responses
 * 
 * Handles the standard PureStorage API structure:
 * {
 *   "items": [ {...}, {...} ],
 *   "continuation_token": null,
 *   "more_items_remaining": false,
 *   "total_item_count": null,
 *   "total": [ {...} ]  // Optional aggregated data
 * }
 */
class PureStorageParser
{
    /**
     * Parse a PureStorage API response
     * 
     * @param array $response The decoded JSON response
     * @param string $endpointName Name of the endpoint for logging
     * @return array Parsed metrics ready for flattening
     */
    public static function parse(array $response, string $endpointName = 'unknown'): array
    {
        $result = [];
        
        // Check if this looks like a PureStorage response
        if (!isset($response['items']) || !is_array($response['items'])) {
            Log::debug("[{$endpointName}] Response doesn't match PureStorage structure, returning as-is");
            return $response;
        }
        
        Log::debug("[{$endpointName}] Detected PureStorage API format with " . count($response['items']) . " items");
        
        // Handle single-item responses (like /arrays) - return the first item
        if (count($response['items']) === 1) {
            Log::debug("[{$endpointName}] Single item response - extracting directly");
            $result = $response['items'][0];
            
            // Also include 'total' data if present (for volumes, etc.)
            if (isset($response['total']) && is_array($response['total']) && !empty($response['total'])) {
                // Prefix total metrics to avoid collisions
                foreach ($response['total'][0] as $key => $value) {
                    if ($key === 'name' || $key === 'id') continue; // Skip ID fields from totals
                    $result["total_{$key}"] = $value;
                }
            }
        }
        // Handle multi-item responses
        else if (count($response['items']) > 1) {
            Log::debug("[{$endpointName}] Multi-item response - creating per-item metrics");
            
            // For arrays of items, we need to handle them differently based on what they represent
            $result = self::handleMultipleItems($response['items'], $endpointName);
            
            // Also include 'total' data if present
            if (isset($response['total']) && is_array($response['total']) && !empty($response['total'])) {
                foreach ($response['total'][0] as $key => $value) {
                    if ($key === 'name' || $key === 'id') continue;
                    $result["total_{$key}"] = $value;
                }
            }
        }
        // Empty response
        else {
            Log::debug("[{$endpointName}] Empty items array");
            $result['items_count'] = 0;
        }
        
        return $result;
    }
    
    /**
     * Handle responses with multiple items
     * 
     * @param array $items Array of items from the response
     * @param string $endpointName Endpoint name for logging
     * @return array Processed metrics
     */
    protected static function handleMultipleItems(array $items, string $endpointName): array
    {
        $result = [];
        
        // Add item count
        $result['items_count'] = count($items);
        
        // Determine what type of data this is based on the keys
        $firstItem = $items[0];
        
        // Check if items have a 'name' field - these can be indexed by name
        if (isset($firstItem['name'])) {
            Log::debug("[{$endpointName}] Items have names - creating named metrics");
            
            foreach ($items as $item) {
                $name = $item['name'];
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
                
                // For each item, create metrics with the item name as prefix
                foreach ($item as $key => $value) {
                    if ($key === 'name') continue; // Skip the name itself
                    
                    // Handle nested data
                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if (!is_array($subValue)) {
                                $result["{$safeName}_{$key}_{$subKey}"] = $subValue;
                            }
                        }
                    } else {
                        $result["{$safeName}_{$key}"] = $value;
                    }
                }
            }
        }
        // Items don't have names - just count them and potentially aggregate
        else {
            Log::debug("[{$endpointName}] Items don't have names - creating aggregated metrics");
            
            // For unnamed items, we might want to aggregate or just count
            // This depends on the endpoint type
            $result['items_count'] = count($items);
            
            // Try to find numeric fields we can aggregate
            $numericFields = [];
            foreach ($firstItem as $key => $value) {
                if (is_numeric($value)) {
                    $numericFields[] = $key;
                }
            }
            
            // Aggregate numeric fields
            foreach ($numericFields as $field) {
                $sum = 0;
                $count = 0;
                $min = null;
                $max = null;
                
                foreach ($items as $item) {
                    if (isset($item[$field]) && is_numeric($item[$field])) {
                        $val = $item[$field];
                        $sum += $val;
                        $count++;
                        $min = ($min === null) ? $val : min($min, $val);
                        $max = ($max === null) ? $val : max($max, $val);
                    }
                }
                
                if ($count > 0) {
                    $result["{$field}_sum"] = $sum;
                    $result["{$field}_avg"] = $sum / $count;
                    $result["{$field}_min"] = $min;
                    $result["{$field}_max"] = $max;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a response looks like it's from PureStorage API
     * 
     * @param array $response The decoded JSON response
     * @return bool True if it matches PureStorage format
     */
    public static function isPureStorageResponse(array $response): bool
    {
        return isset($response['items']) 
            && is_array($response['items'])
            && (isset($response['continuation_token']) || isset($response['more_items_remaining']));
    }
}
