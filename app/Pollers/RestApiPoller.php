<?php
namespace App\Pollers;

use App\Models\Device;
use App\RestApi\Metrics\MetricsStager;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utils\JsonFlattener;
// use App\RestApi\Parsers\PureStorageParser; // Optional parser - not required
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Log;

class RestApiPoller
{
    protected Device $device;
    protected MetricsStager $stager;
    protected array $sessionTokens = []; // Cache session tokens per connection

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->stager = new MetricsStager($device);
    }

    public function poll()
    {
        // FIXED: Properly eager load ALL credential relationships
        $connections = $this->device->restApiConnections()
            ->where('enabled', 1)
            ->with([
                'credential' => function($query) {
                    $query->with(['authenticationType', 'params']);
                },
                'endpoints'
            ])
            ->get();

        Log::info("REST API Polling started for device {$this->device->hostname} with " . $connections->count() . " connections");

        foreach ($connections as $conn) {
            // Skip if no credential
            if (!$conn->credential) {
                Log::warning("REST API connection '{$conn->name}' has no credential attached");
                continue;
            }

            // Verify credential has required relationships
            if (!$conn->credential->relationLoaded('authenticationType')) {
                Log::error("Credential '{$conn->credential->name}' missing authenticationType relationship");
                continue;
            }

            if (!$conn->credential->relationLoaded('params')) {
                Log::error("Credential '{$conn->credential->name}' missing params relationship");
                continue;
            }

            Log::debug("Processing connection: {$conn->name} with credential: {$conn->credential->name} (Auth: {$conn->credential->authenticationType->name})");

            foreach ($conn->endpoints as $endpoint) {
                try {
                    Log::debug("[{$endpoint->name}] Starting to process endpoint: {$endpoint->path}");
                    
                    $response = $this->requestEndpoint($conn, $endpoint);
                    
                    // Get metric map from endpoint if available
                    $metricMap = is_array($endpoint->metric_map) ? $endpoint->metric_map : [];
                    $resourceType = $endpoint->resource_type ?? 'custom';
                    
                    // Detect if this is a multi-item response (common pattern in REST APIs)
                    if ($this->isMultiItemResponse($response)) {
                        Log::info("[{$endpoint->name}] Detected multi-item response - processing items individually");
                        $this->processMultiItemResponse($response, $endpoint, $metricMap, $resourceType);
                    } else {
                        // Single item or simple response - flatten and process normally
                        Log::debug("[{$endpoint->name}] Single-item response - flattening");
                        $metrics = JsonFlattener::flatten($response);
                        
                        Log::debug("[{$endpoint->name}] Flattener returned " . count($metrics) . " metrics");
                        
                        $this->stager->stageMetrics(
                            $metrics, 
                            true, // isPoller
                            $resourceType,
                            $metricMap,
                            $endpoint->name
                        );
                    }
                    
                    Log::info("REST API polling successful for {$endpoint->name} on {$this->device->hostname}");
                } catch (\Exception $e) {
                    Log::error("Polling failed for {$endpoint->name}: {$e->getMessage()}");
                    Log::error("Stack trace: " . $e->getTraceAsString());
                }
            }
        }

        Log::info("REST API Polling completed for device {$this->device->hostname}");
    }

    /**
     * Process a structured response from PureStorageParser
     * 
     * @param array $parsedResponse Structured response with 'type', 'items', 'aggregated'
     * @param object $endpoint The endpoint configuration
     */
    protected function processStructuredResponse(array $parsedResponse, $endpoint): void
    {
        $metricMap = is_array($endpoint->metric_map) ? $endpoint->metric_map : [];
        $resourceType = $endpoint->resource_type ?? 'custom';
        
        Log::debug("[{$endpoint->name}] Processing structured response of type: {$parsedResponse['type']}");
        
        switch ($parsedResponse['type']) {
            case 'empty':
                Log::info("[{$endpoint->name}] Empty response - no items to process");
                break;
                
            case 'single-item':
                // Single item - treat as device-level data
                Log::debug("[{$endpoint->name}] Processing single item as device-level data");
                $item = $parsedResponse['items'][0];
                $metrics = JsonFlattener::flatten($item);
                
                // Also include aggregated data if present
                if (!empty($parsedResponse['aggregated'])) {
                    $aggregatedMetrics = JsonFlattener::flatten($parsedResponse['aggregated']);
                    foreach ($aggregatedMetrics as $key => $value) {
                        if (!isset($metrics[$key])) { // Don't overwrite item data
                            $metrics["total_{$key}"] = $value;
                        }
                    }
                }
                
                Log::debug("[{$endpoint->name}] Flattened to " . count($metrics) . " metrics");
                $this->stager->stageMetrics(
                    $metrics,
                    true,
                    $resourceType,
                    $metricMap,
                    $endpoint->name
                );
                break;
                
            case 'multi-item':
                // Multiple items - process each separately
                $itemCount = count($parsedResponse['items']);
                Log::info("[{$endpoint->name}] Processing {$itemCount} items individually");
                
                // First, process aggregated data if present
                if (!empty($parsedResponse['aggregated'])) {
                    Log::debug("[{$endpoint->name}] Processing aggregated/total data");
                    $aggregatedMetrics = JsonFlattener::flatten($parsedResponse['aggregated']);
                    
                    // Prefix aggregated metrics to distinguish them
                    $prefixedMetrics = [];
                    foreach ($aggregatedMetrics as $key => $value) {
                        $prefixedMetrics["total_{$key}"] = $value;
                    }
                    
                    $this->stager->stageMetrics(
                        $prefixedMetrics,
                        true,
                        $resourceType,
                        $metricMap,
                        $endpoint->name,
                        ['type' => 'aggregated']
                    );
                }
                
                // Now process each individual item
                foreach ($parsedResponse['items'] as $index => $item) {
                    // Build item context for identification
                    $itemContext = [
                        'name' => $item['name'] ?? null,
                        'id' => $item['id'] ?? null,
                        'index' => $index,
                    ];
                    
                    // Remove metadata fields before flattening
                    $cleanItem = $this->removeMetadataFields($item);
                    
                    $metrics = JsonFlattener::flatten($cleanItem);
                    
                    $itemLabel = $itemContext['name'] ?? $itemContext['id'] ?? "item_{$index}";
                    Log::debug("[{$endpoint->name}] Processing item: {$itemLabel} ({" . count($metrics) . "} metrics)");
                    
                    $this->stager->stageMetrics(
                        $metrics,
                        true,
                        $resourceType,
                        $metricMap,
                        $endpoint->name,
                        $itemContext
                    );
                }
                
                Log::info("[{$endpoint->name}] Completed processing {$itemCount} items");
                break;
                
            case 'legacy':
                // Fallback for non-structured responses
                Log::debug("[{$endpoint->name}] Legacy response format");
                $metrics = JsonFlattener::flatten($parsedResponse['data']);
                $this->stager->stageMetrics(
                    $metrics,
                    true,
                    $resourceType,
                    $metricMap,
                    $endpoint->name
                );
                break;
                
            default:
                Log::warning("[{$endpoint->name}] Unknown response type: {$parsedResponse['type']}");
        }
    }

    /**
     * Remove metadata fields that shouldn't be stored as metrics
     */
    protected function removeMetadataFields(array $item): array
    {
        $metadataFields = ['id', 'resource_type', 'continuation_token', 'more_items_remaining'];
        
        foreach ($metadataFields as $field) {
            unset($item[$field]);
        }
        
        return $item;
    }

    protected function requestEndpoint($connection, $endpoint): array
    {
        $client = new Client([
            'base_uri' => $connection->base_url,
            'timeout' => 15,
            'verify' => !$connection->disable_ssl_verify,
        ]);

        // Get authentication headers
        $headers = $this->getAuthHeaders($connection, $client);
        
        if (empty($headers)) {
            throw new \Exception("No authentication headers generated");
        }

        // Log headers for debugging (mask sensitive data)
        $safeHeaders = array_map(function($value) {
            return strlen($value) > 10 ? substr($value, 0, 10) . '...' : $value;
        }, $headers);
        Log::debug("REST API request to {$endpoint->path} with headers: " . json_encode($safeHeaders));

        $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

        if ($res->getStatusCode() != 200) {
            throw new \Exception("HTTP error {$res->getStatusCode()}");
        }

        $body = (string)$res->getBody();
        
        // Check if we got HTML instead of JSON (session expired)
        if (stripos($body, '<!DOCTYPE html>') !== false || stripos($body, '<html') !== false) {
            Log::warning("[{$endpoint->name}] Received HTML instead of JSON - session token likely expired");
            
            // Clear cached token and retry once
            if ($connection->credential && Str::lower($connection->credential->authenticationType->name) === 'session token') {
                $cacheKey = "connection_{$connection->id}";
                unset($this->sessionTokens[$cacheKey]);
                Log::info("[{$endpoint->name}] Clearing cached token and retrying...");
                
                // Get new token and retry
                $headers = $this->getAuthHeaders($connection, $client);
                if (!empty($headers)) {
                    $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);
                    $body = (string)$res->getBody();
                }
            }
        }
        
        // Log the raw response for debugging
        Log::debug("[{$endpoint->name}] Raw API response (first 500 chars): " . substr($body, 0, 500));
        
        $decoded = json_decode($body, true);
        if (!$decoded) {
            $jsonError = json_last_error_msg();
            Log::error("[{$endpoint->name}] JSON decode failed: {$jsonError}");
            Log::error("[{$endpoint->name}] Response body (first 2000 chars): " . substr($body, 0, 2000));
            
            // Check if response is still HTML after retry
            if (stripos($body, '<!DOCTYPE html>') !== false || stripos($body, '<html') !== false) {
                Log::error("[{$endpoint->name}] Still receiving HTML after token refresh - endpoint may be invalid or require different auth");
                throw new \Exception("Endpoint returned HTML instead of JSON even after token refresh");
            }
            
            throw new \Exception("Invalid JSON response: {$jsonError}");
        }
        
        // Log the structure of decoded data
        Log::debug("[{$endpoint->name}] Decoded response keys: " . implode(', ', array_keys($decoded)));
        
        return $decoded;
    }

    /**
     * Get authentication headers, handling two-stage auth if needed
     */
    protected function getAuthHeaders($connection, $client): array
    {
        $credential = $connection->credential;
        
        // Safety check
        if (!$credential->relationLoaded('authenticationType') || !$credential->relationLoaded('params')) {
            Log::error("Credential relationships not loaded properly");
            return [];
        }

        $authType = Str::lower($credential->authenticationType->name);
        Log::debug("Getting auth headers for type: {$authType}");

        // Check if this is a two-stage session token auth
        if ($authType === 'session token') {
            // Check if we already have a cached token for this connection
            $cacheKey = "connection_{$connection->id}";
            
            if (!isset($this->sessionTokens[$cacheKey])) {
                // Obtain new session token
                Log::info("Obtaining session token for connection: {$connection->name}");
                
                // Build connection config from credential params
                $params = $credential->params->pluck('value', 'key')->toArray();
                $connectionConfig = [
                    'login_path' => $params['login_path'] ?? '/api/login',
                    'login_method' => $params['login_method'] ?? 'POST',
                    'api_token_header' => $params['api_token_header'] ?? 'api-token',
                    'session_token_header' => $params['session_token_header'] ?? 'x-auth-token',
                    'login_body' => $params['login_body'] ?? '',
                ];
                
                $sessionToken = CredentialHelper::obtainSessionToken(
                    $credential,
                    $connection->base_url,
                    $connectionConfig,
                    !$connection->disable_ssl_verify
                );
                
                if (!$sessionToken) {
                    Log::error("Failed to obtain session token for connection: {$connection->name}");
                    return [];
                }
                
                // Cache the token for this polling cycle
                $this->sessionTokens[$cacheKey] = $sessionToken;
                Log::info("Session token cached successfully for connection: {$connection->name}");
            } else {
                Log::debug("Using cached session token for connection: {$connection->name}");
            }
            
            // Build headers with the session token
            $params = $credential->params->pluck('value', 'key');
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            
            return [
                $tokenHeader => $this->sessionTokens[$cacheKey],
            ];
        }

        // For non-session token auth types, use the standard method
        $headers = CredentialHelper::getAuthHeaderFromModel($credential);
        Log::debug("Generated " . count($headers) . " auth headers for type: {$authType}");
        
        return $headers;
    }

    /**
     * Detect if response contains multiple items
     */
    protected function isMultiItemResponse(array $response): bool
    {
        // Check for common multi-item patterns
        if (isset($response['items']) && is_array($response['items']) && count($response['items']) > 0) {
            return true;
        }
        
        if (isset($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
            // Check if data is an array of objects
            $first = reset($response['data']);
            return is_array($first);
        }
        
        return false;
    }

    /**
     * Process multi-item response by iterating each item
     */
    protected function processMultiItemResponse(array $response, $endpoint, array $metricMap, string $resourceType): void
    {
        // Find the items array
        $items = $response['items'] ?? $response['data'] ?? [];
        
        if (empty($items)) {
            Log::warning("[{$endpoint->name}] Multi-item response detected but no items found");
            return;
        }
        
        $itemCount = count($items);
        Log::info("[{$endpoint->name}] Processing {$itemCount} items individually");
        
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                Log::debug("[{$endpoint->name}] Skipping non-array item at index {$index}");
                continue;
            }
            
            // Build item context for identification
            $itemContext = [
                'name' => $item['name'] ?? null,
                'id' => $item['id'] ?? null,
                'index' => $index,
            ];
            
            // Remove pagination metadata
            unset($item['continuation_token'], $item['more_items_remaining'], $item['total_item_count']);
            
            // Flatten this individual item
            $metrics = JsonFlattener::flatten($item);
            
            $itemLabel = $itemContext['name'] ?? $itemContext['id'] ?? "item_{$index}";
            Log::debug("[{$endpoint->name}] Processing item: {$itemLabel} (" . count($metrics) . " metrics)");
            
            // Stage with item context so DataRouter can identify the item
            $this->stager->stageMetrics(
                $metrics,
                true,
                $resourceType,
                $metricMap,
                $endpoint->name,
                $itemContext
            );
        }
        
        Log::info("[{$endpoint->name}] Completed processing {$itemCount} items");
    }
}
