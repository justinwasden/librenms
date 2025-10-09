<?php
namespace App\Pollers;

use App\Models\Device;
use App\RestApi\Metrics\MetricsStager;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utils\JsonFlattener;
use App\RestApi\Parsers\PureStorageParser;
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
                    
                    // Check if this is a PureStorage API response and parse it
                    if (PureStorageParser::isPureStorageResponse($response)) {
                        Log::debug("[{$endpoint->name}] Detected PureStorage API format - parsing");
                        $response = PureStorageParser::parse($response, $endpoint->name);
                    }
                    
                    Log::debug("[{$endpoint->name}] Flattening response with prefix: '{$endpoint->resource_type}_'");
                    $metrics = JsonFlattener::flatten($response, $endpoint->resource_type . '_');
                    
                    Log::debug("[{$endpoint->name}] Flattener returned " . count($metrics) . " metrics");
                    
                    // Get metric map from endpoint if available
                    $metricMap = is_array($endpoint->metric_map) ? $endpoint->metric_map : [];
                    
                    $this->stager->stageMetrics(
                        $metrics, 
                        true, // isPoller
                        $endpoint->resource_type ?? 'custom',
                        $metricMap,
                        $endpoint->name
                    );
                    
                    Log::info("REST API polling successful for {$endpoint->name} on {$this->device->hostname}");
                } catch (\Exception $e) {
                    Log::error("Polling failed for {$endpoint->name}: {$e->getMessage()}");
                }
            }
        }

        Log::info("REST API Polling completed for device {$this->device->hostname}");
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
        
        // Log the raw response for debugging
        Log::debug("[{$endpoint->name}] Raw API response (first 500 chars): " . substr($body, 0, 500));
        
        $decoded = json_decode($body, true);
        if (!$decoded) {
            $jsonError = json_last_error_msg();
            Log::error("[{$endpoint->name}] JSON decode failed: {$jsonError}");
            Log::error("[{$endpoint->name}] Response body (first 1000 chars): " . substr($body, 0, 1000));
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
}
