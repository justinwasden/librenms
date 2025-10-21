<?php

namespace App\RestApi\Credentials;

use App\Models\RestApiCredential;
use Illuminate\Support\Str;

class CredentialHelper
{
    /**
     * Get authentication headers for a credential
     * 
     * @param array $credential Credential data with params
     * @return array Headers array
     */
    public static function getAuthHeader(array $credential): array
    {
        $authType = $credential['authentication_type']['name'] ?? $credential['authenticationType']['name'] ?? '';
        
        // Get params as key-value pairs
        $params = [];
        if (isset($credential['params'])) {
            foreach ($credential['params'] as $param) {
                if (is_array($param) && isset($param['key'], $param['value'])) {
                    $params[$param['key']] = $param['value'];
                } elseif (is_object($param) && isset($param->key, $param->value)) {
                    $params[$param->key] = $param->value;
                }
            }
        }
        
        return self::buildHeadersForType($authType, $params);
    }
    
    /**
     * Get authentication headers from a RestApiCredential model
     * 
     * @param RestApiCredential $credential
     * @return array Headers array
     */
    public static function getAuthHeaderFromModel(RestApiCredential $credential): array
    {
        $params = $credential->params->pluck('value', 'key')->toArray();
        $authType = $credential->authenticationType->name;
        
        return self::buildHeadersForType($authType, $params);
    }
    
    /**
     * Build headers based on authentication type
     * 
     * @param string $authType Authentication type name
     * @param array $params Authentication parameters
     * @return array Headers array
     */
    protected static function buildHeadersForType(string $authType, array $params): array
    {
        $authTypeLower = Str::lower($authType);
        
        switch ($authTypeLower) {
            case 'bearer token':
                return self::bearerTokenHeaders($params);
                
            case 'api key':
                return self::apiKeyHeaders($params);
                
            case 'basic auth':
            case 'basic authentication':
                return self::basicAuthHeaders($params);
                
            case 'session token':
                return self::sessionTokenHeaders($params);
                
            case 'oauth2':
                return self::oauth2Headers($params);
                
            case 'custom header':
                return self::customHeaders($params);
                
            default:
                return [];
        }
    }
    
    /**
     * Build Bearer Token authentication headers
     */
    protected static function bearerTokenHeaders(array $params): array
    {
        $token = $params['token'] ?? $params['bearer_token'] ?? '';
        
        if (empty($token)) {
            return [];
        }
        
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
    
    /**
     * Build API Key authentication headers
     */
    protected static function apiKeyHeaders(array $params): array
    {
        $apiKey = $params['api_key'] ?? '';
        $headerName = $params['header_name'] ?? 'X-API-Key';
        
        if (empty($apiKey)) {
            return [];
        }
        
        return [
            $headerName => $apiKey,
        ];
    }
    
    /**
     * Build Basic Authentication headers
     */
    protected static function basicAuthHeaders(array $params): array
    {
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';
        
        if (empty($username)) {
            return [];
        }
        
        $auth = base64_encode($username . ':' . $password);
        
        return [
            'Authorization' => 'Basic ' . $auth,
        ];
    }
    
    /**
     * Build Session Token headers (for tokens obtained via login)
     */
    protected static function sessionTokenHeaders(array $params): array
    {
        $sessionToken = $params['session_token'] ?? '';
        $headerName = $params['token_header'] ?? 'X-Auth-Token';
        
        if (empty($sessionToken)) {
            return [];
        }
        
        return [
            $headerName => $sessionToken,
        ];
    }
    
    /**
     * Build OAuth2 headers
     */
    protected static function oauth2Headers(array $params): array
    {
        $accessToken = $params['access_token'] ?? '';
        
        if (empty($accessToken)) {
            return [];
        }
        
        return [
            'Authorization' => 'Bearer ' . $accessToken,
        ];
    }
    
    /**
     * Build custom headers from params
     */
    protected static function customHeaders(array $params): array
    {
        $headers = [];
        
        // Look for header_* params
        foreach ($params as $key => $value) {
            if (Str::startsWith($key, 'header_')) {
                $headerName = Str::after($key, 'header_');
                $headerName = Str::studly($headerName); // Convert to StudlyCase
                $headers[$headerName] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * Obtain a session token by performing a login request
     * Based on your original working implementation
     * 
     * @param RestApiCredential $credential
     * @param string $baseUrl
     * @param array $connectionConfig Additional connection config (login_method, api_token_header, etc.)
     * @param bool $verifySsl
     * @return string|null Session token or null if failed
     */
    public static function obtainSessionToken(
        RestApiCredential $credential, 
        string $baseUrl, 
        array $connectionConfig = [],
        bool $verifySsl = true
    ): ?string {
        if (Str::lower($credential->authenticationType->name) !== 'session token') {
            return null;
        }
        
        $params = $credential->params->pluck('value', 'key')->toArray();
        
        // Get configuration from params or connection config
        $loginPath = $params['login_path'] ?? $connectionConfig['login_path'] ?? '/api/login';
        $loginMethod = Str::upper($params['login_method'] ?? $connectionConfig['login_method'] ?? 'POST');
        $apiTokenHeader = $params['api_token_header'] ?? $connectionConfig['api_token_header'] ?? 'api-token';
        $sessionTokenHeader = $params['session_token_header'] ?? $connectionConfig['session_token_header'] ?? 'x-auth-token';
        $loginBody = $params['login_body'] ?? $connectionConfig['login_body'] ?? '';
        
        // Build login URL
        $url = rtrim($baseUrl, '/') . '/' . ltrim($loginPath, '/');
        
        \Log::info("Attempting session token login to: {$url} with method: {$loginMethod}");
        
        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $loginMethod);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        
        $headers = ['Content-Type: application/json'];
        
        // Prepare the request body
        $data = json_decode($loginBody, true) ?? [];
        
        // Add username/password from credential if not in login_body
        if (empty($data['username']) && !empty($params['username'])) {
            $data['username'] = $params['username'];
        }
        if (empty($data['password']) && !empty($params['password'])) {
            $data['password'] = $params['password'];
        }
        
        // Only set POST body if we have data OR if method requires it
        $postFields = null;
        if (!empty($data) && ($loginMethod === 'POST' || $loginMethod === 'PUT')) {
            $postFields = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            \Log::debug("Login request body: {$postFields}");
        }
        
        // Add API Token header if credential has a token field
        // Check multiple possible param names for the token
        $tokenValue = $params['token'] ?? $params['api_token'] ?? $params['apitoken'] ?? null;
        
        if (!empty($apiTokenHeader) && !empty($tokenValue)) {
            $headers[] = "{$apiTokenHeader}: {$tokenValue}";
            \Log::info("Adding API token header: {$apiTokenHeader} with value: " . substr($tokenValue, 0, 10) . '...');
        } else {
            \Log::error("Cannot add API token header. apiTokenHeader: '{$apiTokenHeader}', token exists: " . ($tokenValue ? 'yes' : 'no'));
            \Log::debug("Available params: " . implode(', ', array_keys($params)));
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers to extract token if not in body
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            \Log::error("cURL error during session token login: {$error}");
            return null;
        }
        
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        // Check HTTP status
        if ($httpCode < 200 || $httpCode >= 300) {
            \Log::error("Failed to fetch session token from {$url}. HTTP Code: {$httpCode}. Response: {$body}");
            return null;
        }
        
        \Log::debug("Login response HTTP code: {$httpCode}");
        
        // 1. Check response body for "token" (standard session response)
        $responseData = json_decode($body, true);
        $token = $responseData['token'] ?? null;
        
        if ($token) {
            \Log::info("Session token found in response body");
            return $token;
        }
        
        // 2. Check headers if no token in body (e.g., PureStorage uses a header)
        if (!empty($sessionTokenHeader)) {
            $sessionHeaderLower = strtolower($sessionTokenHeader);
            
            // Parse headers (case-insensitive)
            if (preg_match("/{$sessionHeaderLower}:\\s*([^\\r\\n]+)/i", $header, $matches)) {
                $token = trim($matches[1]);
                \Log::info("Session token found in response header: {$sessionTokenHeader}");
                return $token;
            }
        }
        
        \Log::error("Session token not found in login response from {$url}");
        \Log::debug("Response headers: {$header}");
        \Log::debug("Response body: {$body}");
        
        return null;
    }
}
