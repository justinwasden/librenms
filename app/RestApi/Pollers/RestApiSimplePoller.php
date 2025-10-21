<?php

namespace App\RestApi\Pollers;

use App\Models\Device;
use App\Models\RestApiMapping;
use App\Models\RestApiDeviceTemplate;
use App\RestApi\Utils\JsonPathExtractor;
use App\RestApi\Storage\MetricStorageEngine;
use App\RestApi\Services\MapperSelectionService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Log;

/**
 * Simple REST API Poller
 * 
 * Uses static mappings only:
 * 1. Get endpoint mappings from database
 * 2. Fetch API response
 * 3. Extract values using JSONPath
 * 4. Store to database directly
 * 
 * No parsing, no matching, no fallbacks
 */
class RestApiSimplePoller
{
    protected Device $device;
    protected array $sessionTokens = [];
    protected Client $client;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Poll all REST API connections
     */
    public function poll()
    {
        // Get device template configuration
        $deviceTemplate = $this->device->restApiTemplate;
        if (!$deviceTemplate) {
            Log::debug("No REST API template configured for {$this->device->hostname}");
            return;
        }

        // Select appropriate mapper
        $mapperConfig = MapperSelectionService::selectMapper($deviceTemplate);
        $mapper = $mapperConfig['mapper'];

        Log::info("REST API Poller: {$this->device->hostname} using mapper: {$mapperConfig['mapper_name']} (source: {$mapperConfig['source']})");

        $connections = $this->device->restApiConnections()
            ->where('enabled', 1)
            ->with(['credential' => function($q) { $q->with(['authenticationType', 'params']); }, 'template' => function($q) { $q->with('endpoints'); }])
            ->get();

        if ($connections->isEmpty()) {
            Log::debug("No REST API connections configured for {$this->device->hostname}");
            return;
        }

        foreach ($connections as $conn) {
            if (!$conn->credential) {
                Log::warning("REST API: Connection '{$conn->name}' has no credential");
                continue;
            }

            // Initialize HTTP client for this connection
            $this->client = new Client([
                'base_uri' => $conn->base_url,
                'timeout' => 15,
                'verify' => !$conn->disable_ssl_verify,
            ]);

            foreach ($conn->template->endpoints as $endpoint) {
                $this->pollEndpoint($conn, $endpoint, $mapper);
            }
        }

        Log::info("REST API Poller: {$this->device->hostname} complete");
    }

    /**
     * Poll single endpoint
     */
    protected function pollEndpoint($connection, $endpoint, $mapper)
    {
        try {
            // Get mappings from mapper for this endpoint
            $mappings = $mapper->getMappingsForEndpoint($endpoint->path);

            if (empty($mappings)) {
                Log::debug("[{$endpoint->path}] No mappings defined in mapper");
                return;
            }

            // Fetch API response
            $response = $this->requestEndpoint($connection, $endpoint);

            Log::info("Polling {$connection->base_url}{$endpoint->path}");

            // Store metrics using mapper's field mappings
            $engine = new MetricStorageEngine($this->device);
            $engine->storeFromResponse($response, $endpoint, $mapper);

            Log::info("[{$endpoint->path}] Success");

        } catch (\Exception $e) {
            Log::error("[{$endpoint->path}] Failed: " . $e->getMessage());
        }
    }

    /**
     * Request endpoint from API
     */
    protected function requestEndpoint($connection, $endpoint): array
    {
        // Get auth headers
        $headers = $this->getAuthHeaders($connection);
        if (empty($headers)) {
            throw new \Exception("Failed to obtain authentication headers");
        }

        try {
            $res = $this->client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

            if ($res->getStatusCode() != 200) {
                throw new \Exception("HTTP {$res->getStatusCode()}");
            }

            $body = (string)$res->getBody();
        } catch (RequestException $e) {
            throw new \Exception("Request failed: " . $e->getMessage());
        }

        // Check for HTML (session expired)
        if (stripos($body, '<!DOCTYPE html>') !== false) {
            Log::warning("Session expired for {$connection->name}, retrying with new token");
            $cacheKey = "rest_token_{$connection->id}";
            unset($this->sessionTokens[$cacheKey]);
            
            $headers = $this->getAuthHeaders($connection);
            $res = $this->client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);
            $body = (string)$res->getBody();
        }

        $decoded = json_decode($body, true);
        if (!$decoded) {
            throw new \Exception("Invalid JSON response");
        }

        return $decoded;
    }

    /**
     * Get authentication headers based on credential type
     */
    protected function getAuthHeaders($connection): array
    {
        $credential = $connection->credential;
        $authType = Str::lower($credential->authenticationType->name ?? '');
        $params = $credential->params->pluck('value', 'key')->toArray();

        switch ($authType) {
            case 'api token':
                $header = $params['api_token_header'] ?? 'Authorization';
                $value = "Bearer " . ($params['api_token'] ?? '');
                return [$header => $value];

            case 'bearer token':
                $token = $params['bearer_token'] ?? '';
                return ['Authorization' => "Bearer $token"];

            case 'basic auth':
                $username = $params['username'] ?? '';
                $password = $params['password'] ?? '';
                $encoded = base64_encode("$username:$password");
                return ['Authorization' => "Basic $encoded"];

            case 'session token':
                return $this->getSessionTokenHeaders($connection, $params);

            default:
                return [];
        }
    }

    /**
     * Get session token headers (two-stage authentication)
     */
    protected function getSessionTokenHeaders($connection, array $params): array
    {
        $cacheKey = "rest_token_{$connection->id}";

        // Return cached token if available
        if (isset($this->sessionTokens[$cacheKey])) {
            $tokenHeader = $params['session_token_header'] ?? 'x-auth-token';
            return [$tokenHeader => $this->sessionTokens[$cacheKey]];
        }

        // Obtain new session token
        $token = $this->obtainSessionToken($connection, $params);
        if (!$token) {
            Log::error("Failed to obtain session token for {$connection->name}");
            return [];
        }

        // Cache the token
        $this->sessionTokens[$cacheKey] = $token;

        $tokenHeader = $params['session_token_header'] ?? 'x-auth-token';
        return [$tokenHeader => $token];
    }

    /**
     * Obtain session token from login endpoint
     * 
     * This implements Pure Storage's two-stage authentication:
     * 1. POST to login endpoint with API token
     * 2. Extract session token from response header
     */
    protected function obtainSessionToken($connection, array $params): ?string
    {
        $loginPath = $params['login_path'] ?? '/login';
        $apiTokenHeader = $params['api_token_header'] ?? 'api-token';
        $apiToken = $params['api_token'] ?? '';
        $sessionTokenHeader = $params['session_token_header'] ?? 'x-auth-token';

        if (!$apiToken) {
            Log::error("No API token configured for session authentication");
            return null;
        }

        try {
            $response = $this->client->request('POST', $loginPath, [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Extract session token from response header
            if ($response->hasHeader($sessionTokenHeader)) {
                $token = $response->getHeader($sessionTokenHeader)[0] ?? null;
                if ($token) {
                    Log::info("Successfully obtained session token for {$connection->name}");
                    return $token;
                }
            }

            Log::error("Session token header '{$sessionTokenHeader}' not found in login response");
            return null;

        } catch (RequestException $e) {
            Log::error("Failed to obtain session token: " . $e->getMessage());
            return null;
        }
    }
}
