<?php

namespace App\Pollers;

use App\Models\Device;
use App\RestApi\Data\DataRouter;
use App\RestApi\Credentials\CredentialHelper;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Log;

/**
 * Clean REST API Poller
 * Uses template-based mappings only - no parsing, no matching, no fallbacks
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
     * Poll all REST API connections for device
     */
    public function poll()
    {
        $connections = $this->device->restApiConnections()
            ->where('enabled', 1)
            ->with(['credential' => function($q) { $q->with(['authenticationType', 'params']); }, 'endpoints'])
            ->get();

        Log::info("REST API Poller: {$this->device->hostname} with " . $connections->count() . " connections");

        foreach ($connections as $conn) {
            if (!$conn->credential || !$conn->credential->relationLoaded('authenticationType')) {
                Log::warning("REST API: Connection '{$conn->name}' missing credential/auth");
                continue;
            }

            Log::debug("REST API: Processing {$conn->name}");

            foreach ($conn->endpoints as $endpoint) {
                try {
                    // Request endpoint
                    $response = $this->requestEndpoint($conn, $endpoint);

                    // Get mappings from template
                    $template = $endpoint->template;
                    if (!$template || empty($template->template_response_mapping)) {
                        Log::warning("[{$endpoint->name}] No mappings defined");
                        continue;
                    }

                    $mappings = json_decode($template->template_response_mapping, true);
                    if (!$mappings) {
                        Log::warning("[{$endpoint->name}] Invalid mapping JSON");
                        continue;
                    }

                    // Route directly to database using template mappings
                    $router = new DataRouter($this->device, $mappings);
                    $router->routeByTemplate($response, $mappings, $endpoint->name);

                    Log::info("[{$endpoint->name}] Polling successful");
                } catch (\Exception $e) {
                    Log::error("[{$endpoint->name}] Polling failed: " . $e->getMessage());
                }
            }
        }

        Log::info("REST API Poller: {$this->device->hostname} completed");
    }

    /**
     * Request endpoint and return decoded JSON
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
            throw new \Exception("No authentication headers");
        }

        Log::debug("[{$endpoint->name}] Requesting {$endpoint->path}");

        $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

        if ($res->getStatusCode() != 200) {
            throw new \Exception("HTTP {$res->getStatusCode()}");
        }

        $body = (string)$res->getBody();

        // Check for HTML (session expired)
        if (stripos($body, '<!DOCTYPE html>') !== false) {
            Log::warning("[{$endpoint->name}] Received HTML - session expired, retrying");

            // Invalidate cached token and retry
            $cacheKey = "rest_api_token_" . $connection->id;
            unset($this->sessionTokens[$cacheKey]);

            $headers = $this->getAuthHeaders($connection, $client);
            $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);
            $body = (string)$res->getBody();
        }

        $decoded = json_decode($body, true);
        if (!$decoded) {
            Log::error("[{$endpoint->name}] JSON decode failed: " . json_last_error_msg());
            throw new \Exception("Invalid JSON response");
        }

        return $decoded;
    }

    /**
     * Get auth headers for connection
     */
    protected function getAuthHeaders($connection, $client): array
    {
        $credential = $connection->credential;
        $authType = Str::lower($credential->authenticationType->name);

        // Session token auth
        if ($authType === 'session token') {
            $cacheKey = "rest_api_token_" . $connection->id;

            if (!isset($this->sessionTokens[$cacheKey])) {
                $params = $credential->params->pluck('value', 'key')->toArray();
                $config = [
                    'login_path' => $params['login_path'] ?? '/api/login',
                    'login_method' => $params['login_method'] ?? 'POST',
                    'session_token_header' => $params['session_token_header'] ?? 'x-auth-token',
                ];

                $token = CredentialHelper::obtainSessionToken(
                    $credential,
                    $connection->base_url,
                    $config,
                    !$connection->disable_ssl_verify
                );

                if (!$token) {
                    throw new \Exception("Failed to obtain session token");
                }

                $this->sessionTokens[$cacheKey] = $token;
                Log::info("[REST API] Session token cached for {$connection->name}");
            }

            $tokenHeader = $credential->params->pluck('value', 'key')['session_token_header'] ?? 'x-auth-token';
            return [$tokenHeader => $this->sessionTokens[$cacheKey]];
        }

        // Other auth types
        return CredentialHelper::getAuthHeaderFromModel($credential);
    }
}
