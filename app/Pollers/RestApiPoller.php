<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiMapping;
use App\RestApi\Utils\JsonPathExtractor;
use App\RestApi\Storage\MetricStorageEngine;
use App\RestApi\Credentials\CredentialHelper;
use GuzzleHttp\Client;
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
class RestApiPoller
{
    protected Device $device;
    protected array $sessionTokens = [];

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Poll all REST API connections
     */
    public function poll()
    {
        $connections = $this->device->restApiConnections()
            ->where('enabled', 1)
            ->with(['credential' => function($q) { $q->with(['authenticationType', 'params']); }, 'endpoints' => function($q) { $q->with('mappings'); }])
            ->get();

        Log::info("REST API Poller: {$this->device->hostname} ({$connections->count()} connections)");

        foreach ($connections as $conn) {
            if (!$conn->credential) {
                Log::warning("REST API: Connection '{$conn->name}' has no credential");
                continue;
            }

            foreach ($conn->endpoints as $endpoint) {
                $this->pollEndpoint($conn, $endpoint);
            }
        }

        Log::info("REST API Poller: {$this->device->hostname} complete");
    }

    /**
     * Poll single endpoint
     */
    protected function pollEndpoint($connection, $endpoint)
    {
        try {
            // Get all mappings for this endpoint
            $mappings = RestApiMapping::where('endpoint_id', $endpoint->id)
                ->where('enabled', 1)
                ->get()
                ->groupBy('target_table');

            if ($mappings->isEmpty()) {
                Log::debug("[{$endpoint->name}] No mappings defined");
                return;
            }

            // Fetch API response
            $response = $this->requestEndpoint($connection, $endpoint);

            Log::info("[{$endpoint->name}] Polling {$connection->base_url}{$endpoint->path}");

            // Store metrics using mappings
            $engine = new MetricStorageEngine($this->device);
            $engine->store($response, $mappings, $endpoint->name);

            Log::info("[{$endpoint->name}] Success");

        } catch (\Exception $e) {
            Log::error("[{$endpoint->name}] Failed: " . $e->getMessage());
        }
    }

    /**
     * Request endpoint from API
     */
    protected function requestEndpoint($connection, $endpoint): array
    {
        $client = new Client([
            'base_uri' => $connection->base_url,
            'timeout' => 15,
            'verify' => !$connection->disable_ssl_verify,
        ]);

        // Get auth headers
        $headers = $this->getAuthHeaders($connection, $client);
        if (empty($headers)) {
            throw new \Exception("No auth headers");
        }

        $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

        if ($res->getStatusCode() != 200) {
            throw new \Exception("HTTP {$res->getStatusCode()}");
        }

        $body = (string)$res->getBody();

        // Check for HTML (session expired)
        if (stripos($body, '<!DOCTYPE html>') !== false) {
            Log::warning("[{$endpoint->name}] Session expired, retrying");
            $cacheKey = "rest_token_{$connection->id}";
            unset($this->sessionTokens[$cacheKey]);
            $headers = $this->getAuthHeaders($connection, $client);
            $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);
            $body = (string)$res->getBody();
        }

        $decoded = json_decode($body, true);
        if (!$decoded) {
            throw new \Exception("Invalid JSON");
        }

        return $decoded;
    }

    /**
     * Get authentication headers
     */
    protected function getAuthHeaders($connection, $client): array
    {
        $credential = $connection->credential;
        $authType = Str::lower($credential->authenticationType->name ?? 'none');

        if ($authType === 'session token') {
            $cacheKey = "rest_token_{$connection->id}";

            if (!isset($this->sessionTokens[$cacheKey])) {
                // Get session token via login
                $params = $credential->params->pluck('value', 'key')->toArray();
                $config = [
                    'login_path' => $params['login_path'] ?? '/login',
                    'login_method' => $params['login_method'] ?? 'POST',
                    'api_token_header' => $params['api_token_header'] ?? 'api-token',
                    'session_token_header' => $params['session_token_header'] ?? 'x-auth-token',
                ];

                $token = CredentialHelper::obtainSessionToken(
                    $credential,
                    $connection->base_url,
                    $config,
                    !$connection->disable_ssl_verify
                );

                if (!$token) {
                    Log::error("Failed to obtain session token for connection: {$connection->name}");
                    return [];
                }

                $this->sessionTokens[$cacheKey] = $token;
                Log::info("[REST API] Session token cached for {$connection->name}");
            }

            $tokenHeader = $credential->params->pluck('value', 'key')['session_token_header'] ?? 'x-auth-token';
            return [$tokenHeader => $this->sessionTokens[$cacheKey]];
        }

        // Use CredentialHelper for other auth types
        return CredentialHelper::getAuthHeaderFromModel($credential);
    }
}
