<?php
namespace App\RestApi\Credentials;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CredentialHelper
{
    /**
     * Get an authorization header for a given credential and connection.
     * * @param array $credential The credential details from DB
     * @param array $connection The connection config from the template
     * @param string $baseUrl The resolved base URL (e.g., https://device.example.com)
     */
    public static function getAuthHeader(array $credential, array $connection, string $baseUrl): array
    {
        $type = strtolower($credential['type'] ?? 'none');

        switch ($type) {
            case 'basic':
                $user = $credential['username'] ?? '';
                $pass = $credential['password'] ?? '';
                $encoded = base64_encode("{$user}:{$pass}");
                return ['Authorization' => "Basic {$encoded}"];
            case 'token':
                $token = $credential['token'] ?? '';
                return ['Authorization' => "Bearer {$token}"];
            case 'session':
                $loginUrl = rtrim($baseUrl, '/') . '/' . ltrim($connection['login_path'] ?? 'login', '/');
                $sessionHeader = $connection['session_token_header'] ?? 'X-Auth-Token';

                // Cache session token per connection and credential combo
                $cacheKey = 'restapi_session_' . md5($loginUrl . ($credential['username'] ?? ''));

                if (Cache::has($cacheKey)) {
                    $token = Cache::get($cacheKey);
                } else {
                    // Pass the full connection details and credential for a proper login attempt
                    $token = self::fetchSessionToken($loginUrl, $credential, $connection);
                    // Cache duration should be set based on expected session lifespan, using 5 min default
                    Cache::put($cacheKey, $token, 300);
                }

                // The header to send *with* the subsequent API call
                return [$sessionHeader => $token];
            default:
                Log::warning("Unknown credential type: {$type}");
                return [];
        }
    }

    /**
     * Attempts to fetch a session token from the login endpoint.
     * * @param string $url The full login URL.
     * @param array $credential The credential containing username/password.
     * @param array $connection The connection configuration.
     */
    protected static function fetchSessionToken(string $url, array $credential, array $connection): string
    {
        $method = $connection['login_method'] ?? 'POST';
        $apiTokenHeader = $connection['api_token_header'] ?? 'api-token';
        $loginBody = $connection['login_body'] ?? '';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        $headers = ['Content-Type: application/json'];

        // Prepare the request body
        $data = json_decode($loginBody, true) ?? [];
        if (empty($data['username']) && !empty($credential['username'])) {
            $data['username'] = $credential['username'];
        }
        if (empty($data['password']) && !empty($credential['password'])) {
            $data['password'] = $credential['password'];
        }

        $postFields = json_encode($data);

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }

        // Add API Token header if specified in connection config and credential has a token field
        if (!empty($apiTokenHeader) && !empty($credential['token'])) {
             $headers[] = "{$apiTokenHeader}: {$credential['token']}";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers to extract token if not in body

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $info = curl_getinfo($ch);

        if ($info['http_code'] < 200 || $info['http_code'] >= 300) {
            Log::error("Failed to fetch session token from {$url}. HTTP Code: {$info['http_code']}. Response: {$body}");
            throw new \Exception("Failed to fetch session token from {$url}. HTTP Code: {$info['http_code']}");
        }

        // 1. Check response body for "token" (standard session response)
        $data = json_decode($body, true);
        $token = $data['token'] ?? null;

        // 2. Check headers if no token in body (e.g., PureStorage uses a header)
        if (!$token && !empty($connection['session_token_header'])) {
            $sessionHeaderName = strtolower($connection['session_token_header']);
            // Crude header parsing, should be replaced with a proper HTTP client library
            if (preg_match("/{$sessionHeaderName}:\\s*([^\\r\\n]+)/i", $header, $matches)) {
                $token = trim($matches[1]);
            }
        }

        if (!$token) {
            throw new \Exception("Session token not found in login response from {$url}");
        }

        return $token;
    }
}