<?php

namespace App\RestApi\Credentials;

use App\Models\RestApiCredential;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Log;

/**
 * Credential Helper - Handles authentication for REST API connections
 * 
 * Supports:
 * - API Token (header-based)
 * - Bearer Token (header-based)
 * - Basic Auth (username/password)
 * - Session Token (two-stage: login → token)
 */
class CredentialHelper
{
    /**
     * Get authentication headers from credential model
     * 
     * @param RestApiCredential $credential
     * @return array Headers to use in request
     */
    public static function getAuthHeaderFromModel(RestApiCredential $credential): array
    {
        $authType = Str::lower($credential->authenticationType->name ?? 'none');
        $params = $credential->params->pluck('value', 'key')->toArray();

        switch ($authType) {
            case 'api token':
            case 'api_token':
                $headerName = $params['header_name'] ?? 'X-Auth-Token';
                $token = $params['token'] ?? '';
                return [$headerName => $token];

            case 'bearer token':
            case 'bearer_token':
                $token = $params['token'] ?? '';
                return ['Authorization' => 'Bearer ' . $token];

            case 'basic auth':
            case 'basic_auth':
                $username = $params['username'] ?? '';
                $password = $params['password'] ?? '';
                $encoded = base64_encode("{$username}:{$password}");
                return ['Authorization' => 'Basic ' . $encoded];

            default:
                return [];
        }
    }

    /**
     * Obtain session token via two-stage authentication
     * 
     * Stage 1: POST to login endpoint with API token
     * Stage 2: Extract session token from response header
     * 
     * @param RestApiCredential $credential
     * @param string $baseUrl Base API URL
     * @param array $config Login configuration
     * @param bool $verifySsl Whether to verify SSL
     * @return string|null Session token if successful
     */
    public static function obtainSessionToken(
        RestApiCredential $credential,
        string $baseUrl,
        array $config,
        bool $verifySsl = true
    ): ?string
    {
        try {
            $client = new Client([
                'base_uri' => $baseUrl,
                'verify' => $verifySsl,
                'timeout' => 10,
            ]);

            // Get API token for login
            $params = $credential->params->pluck('value', 'key')->toArray();
            $apiToken = $params['token'] ?? null;

            if (!$apiToken) {
                Log::error("No API token found in credential");
                return null;
            }

            // Build login headers with API token
            $loginHeaders = [
                $params['api_token_header'] ?? 'api-token' => $apiToken,
            ];

            Log::debug("Attempting session token login to: {$baseUrl}{$config['login_path']}");
            Log::debug("Adding API token header: " . ($params['api_token_header'] ?? 'api-token'));

            // Make login request
            $method = strtoupper($config['login_method'] ?? 'POST');
            $response = $client->request($method, $config['login_path'] ?? '/login', [
                'headers' => $loginHeaders,
            ]);

            // Extract session token from response header
            $sessionTokenHeader = $config['session_token_header'] ?? 'x-auth-token';
            $sessionToken = $response->getHeader($sessionTokenHeader)[0] ?? null;

            if (!$sessionToken) {
                Log::error("Session token not found in response header: {$sessionTokenHeader}");
                return null;
            }

            Log::debug("Session token found in response header: {$sessionTokenHeader}");
            return $sessionToken;

        } catch (\Exception $e) {
            Log::error("Failed to obtain session token: " . $e->getMessage());
            return null;
        }
    }
}
