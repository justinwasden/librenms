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
     * Returns a structured array that indicates how to process the data:
     * - 'type': 'multi-item', 'single-item', or 'aggregated'
     * - 'items': Array of items to process individually
     * - 'aggregated': Aggregated/total data to process as device-level metrics
     * - 'metadata': Response metadata
     * 
     * @param array $response The decoded JSON response
     * @param string $endpointName Name of the endpoint for logging
     * @return array Structured response indicating how to process
     */
    public static function parse(array $response, string $endpointName = 'unknown'): array
    {
        // Check if this looks like a PureStorage response
        if (!isset($response['items']) || !is_array($response['items'])) {
            Log::debug("[{$endpointName}] Response doesn't match PureStorage structure, returning as-is");
            return [
                'type' => 'legacy',
                'data' => $response,
            ];
        }
        
        $itemCount = count($response['items']);
        Log::debug("[{$endpointName}] Detected PureStorage API format with {$itemCount} items");
        
        // Prepare result structure
        $result = [
            'type' => null,
            'items' => [],
            'aggregated' => [],
            'metadata' => [
                'item_count' => $itemCount,
                'endpoint' => $endpointName,
            ]
        ];
        
        // Extract aggregated/total data if present
        if (isset($response['total']) && is_array($response['total']) && !empty($response['total'])) {
            Log::debug("[{$endpointName}] Found aggregated 'total' data");
            $result['aggregated'] = $response['total'][0] ?? $response['total'];
        }
        
        // Handle empty response
        if ($itemCount === 0) {
            Log::debug("[{$endpointName}] Empty items array");
            $result['type'] = 'empty';
            return $result;
        }
        
        // Handle single-item response (like /arrays endpoint)
        if ($itemCount === 1) {
            Log::debug("[{$endpointName}] Single item response - treating as device-level data");
            $result['type'] = 'single-item';
            $result['items'] = [$response['items'][0]];
            return $result;
        }
        
        // Handle multi-item response (like /network-interfaces, /volumes, /hardware)
        Log::debug("[{$endpointName}] Multi-item response - will process each item separately");
        $result['type'] = 'multi-item';
        $result['items'] = $response['items'];
        
        // Log sample of first item to help with debugging
        if (!empty($response['items'][0])) {
            $firstItem = $response['items'][0];
            $sampleKeys = array_keys($firstItem);
            Log::debug("[{$endpointName}] Sample item keys: " . implode(', ', array_slice($sampleKeys, 0, 10)));
            
            // Check if items have identifying fields
            $hasName = isset($firstItem['name']);
            $hasId = isset($firstItem['id']);
            Log::debug("[{$endpointName}] Items have identifiers - name: " . ($hasName ? 'yes' : 'no') . ", id: " . ($hasId ? 'yes' : 'no'));
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
