<?php
namespace App\Discovery;

use App\Models\Device;
use App\RestApi\Metrics\MetricsStager;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utils\JsonFlattener;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Log;

class RestApiDiscovery
{
    protected Device $device;
    protected MetricsStager $stager;
    protected array $sessionTokens = [];

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->stager = new MetricsStager($device);
    }

    public function discover()
    {
        $connections = $this->device->restApiConnections()
            ->where('enabled', 1)
            ->with([
                'credential' => function($query) {
                    $query->with(['authenticationType', 'params']);
                },
                'endpoints'
            ])
            ->get();

        Log::info("REST API Discovery started for device {$this->device->hostname} with " . $connections->count() . " connections");

        foreach ($connections as $conn) {
            if (!$conn->credential) {
                Log::warning("REST API connection '{$conn->name}' has no credential attached");
                continue;
            }

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
                    $response = $this->requestEndpoint($conn, $endpoint);
                    
                    $metricMap = is_array($endpoint->metric_map) ? $endpoint->metric_map : [];
                    $resourceType = $endpoint->resource_type ?? 'custom';
                    
                    // Normalize resource type for Pure Storage network interfaces
                    if ($resourceType === 'port' || Str::contains($endpoint->path, 'network-interface')) {
                        $resourceType = 'network-interface';
                    }
                    
                    if ($this->isMultiItemResponse($response)) {
                        Log::info("[{$endpoint->name}] Detected multi-item response - processing items individually");
                        $this->processMultiItemResponse($response, $endpoint, $metricMap, $resourceType);
                    } else {
                        Log::debug("[{$endpoint->name}] Single-item response - flattening");
                        $metrics = JsonFlattener::flatten($response);
                        
                        Log::debug("[{$endpoint->name}] Flattener returned " . count($metrics) . " metrics");
                        
                        $this->stager->stageMetrics(
                            $metrics, 
                            false,
                            $resourceType,
                            $metricMap,
                            $endpoint->name
                        );
                    }
                    
                    Log::info("REST API discovery successful for {$endpoint->name} on {$this->device->hostname}");
                } catch (\Exception $e) {
                    Log::error("Discovery failed for {$endpoint->name} on {$this->device->hostname}: {$e->getMessage()}");
                }
            }
        }

        Log::info("REST API Discovery completed for device {$this->device->hostname}");
    }

    protected function requestEndpoint($connection, $endpoint): array
    {
        $client = new Client([
            'base_uri' => $connection->base_url,
            'timeout' => 15,
            'verify' => !$connection->disable_ssl_verify,
        ]);

        $headers = $this->getAuthHeaders($connection, $client);
        
        if (empty($headers)) {
            throw new \Exception("No authentication headers generated");
        }

        $safeHeaders = array_map(function($value) {
            return strlen($value) > 10 ? substr($value, 0, 10) . '...' : $value;
        }, $headers);
        Log::debug("REST API request to {$endpoint->path} with headers: " . json_encode($safeHeaders));

        $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

        if ($res->getStatusCode() != 200) {
            throw new \Exception("HTTP error {$res->getStatusCode()}");
        }

        $body = (string)$res->getBody();
        
        if (stripos($body, '<!DOCTYPE html>') !== false || stripos($body, '<html') !== false) {
            Log::warning("[{$endpoint->name}] Received HTML instead of JSON - session token likely expired");
            
            if ($connection->credential && Str::lower($connection->credential->authenticationType->name) === 'session token') {
                $cacheKey = "connection_{$connection->id}";
                unset($this->sessionTokens[$cacheKey]);
                Log::info("[{$endpoint->name}] Clearing cached token and retrying...");
                
                $headers = $this->getAuthHeaders($connection, $client);
                if (!empty($headers)) {
                    $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);
                    $body = (string)$res->getBody();
                }
            }
        }
        
        Log::debug("[{$endpoint->name}] Raw API response (first 500 chars): " . substr($body, 0, 500));
        
        $decoded = json_decode($body, true);
        if (!$decoded) {
            $jsonError = json_last_error_msg();
            Log::error("[{$endpoint->name}] JSON decode failed: {$jsonError}");
            Log::error("[{$endpoint->name}] Response body (first 1000 chars): " . substr($body, 0, 1000));
            throw new \Exception("Invalid JSON response: {$jsonError}");
        }
        
        Log::debug("[{$endpoint->name}] Decoded response keys: " . implode(', ', array_keys($decoded)));
        
        return $decoded;
    }

    protected function getAuthHeaders($connection, $client): array
    {
        $credential = $connection->credential;
        
        if (!$credential->relationLoaded('authenticationType') || !$credential->relationLoaded('params')) {
            Log::error("Credential relationships not loaded properly");
            return [];
        }

        $authType = Str::lower($credential->authenticationType->name);
        Log::debug("Getting auth headers for type: {$authType}");

        if ($authType === 'session token') {
            $cacheKey = "connection_{$connection->id}";
            
            if (!isset($this->sessionTokens[$cacheKey])) {
                Log::info("Obtaining session token for connection: {$connection->name}");
                
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
                
                $this->sessionTokens[$cacheKey] = $sessionToken;
                Log::info("Session token cached successfully for connection: {$connection->name}");
            } else {
                Log::debug("Using cached session token for connection: {$connection->name}");
            }
            
            $params = $credential->params->pluck('value', 'key');
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            
            return [
                $tokenHeader => $this->sessionTokens[$cacheKey],
            ];
        }

        $headers = CredentialHelper::getAuthHeaderFromModel($credential);
        Log::debug("Generated " . count($headers) . " auth headers for type: {$authType}");
        
        return $headers;
    }

    protected function isMultiItemResponse(array $response): bool
    {
        if (isset($response['items']) && is_array($response['items']) && count($response['items']) > 0) {
            return true;
        }
        
        if (isset($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
            $first = reset($response['data']);
            return is_array($first);
        }
        
        return false;
    }

    protected function processMultiItemResponse(array $response, $endpoint, array $metricMap, string $resourceType): void
    {
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
            
            $itemContext = [
                'name' => $item['name'] ?? null,
                'id' => $item['id'] ?? null,
                'index' => $index,
            ];
            
            unset($item['continuation_token'], $item['more_items_remaining'], $item['total_item_count']);
            
            $metrics = JsonFlattener::flatten($item);
            
            $itemLabel = $itemContext['name'] ?? $itemContext['id'] ?? "item_{$index}";
            Log::debug("[{$endpoint->name}] Processing item: {$itemLabel} (" . count($metrics) . " metrics)");
            
            $this->stager->stageMetrics(
                $metrics,
                false,
                $resourceType,
                $metricMap,
                $endpoint->name,
                $itemContext
            );
        }
        
        Log::info("[{$endpoint->name}] Completed processing {$itemCount} items");
    }
}
