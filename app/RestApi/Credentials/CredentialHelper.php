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
     * 
     * @param RestApiCredential $credential
     * @param string $baseUrl
     * @param \GuzzleHttp\Client $client
     * @return string|null Session token or null if failed
     */
    public static function obtainSessionToken(RestApiCredential $credential, string $baseUrl, $client): ?string
    {
        if (Str::lower($credential->authenticationType->name) !== 'session token') {
            return null;
        }
        
        $params = $credential->params->pluck('value', 'key');
        
        $apiToken = $params['api_token'] ?? $params['token'] ?? null;
        $loginPath = $params['login_path'] ?? '/api/login';
        $loginMethod = Str::upper($params['login_method'] ?? 'POST');
        $tokenHeader = $params['token_header'] ?? 'X-Auth-Token';
        $apiTokenHeader = $params['api_token_header'] ?? 'X-API-Token';
        
        if (!$apiToken || !$loginPath) {
            return null;
        }
        
        try {
            $loginUrl = rtrim($baseUrl, '/') . '/' . ltrim($loginPath, '/');
            
            $loginOptions = [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
                'verify' => false, // SSL verification handled by connection
            ];
            
            $response = $client->request($loginMethod, $loginUrl, $loginOptions);
            
            // Try to get token from header
            if ($response->hasHeader($tokenHeader)) {
                return $response->getHeader($tokenHeader)[0] ?? null;
            }
            
            // Try to get token from response body
            $body = json_decode($response->getBody(), true);
            if (isset($body['token'])) {
                return $body['token'];
            }
            
            return null;
        } catch (\Exception $e) {
            \Log::error("Failed to obtain session token: " . $e->getMessage());
            return null;
        }
    }
}
