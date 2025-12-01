<?php

namespace App\ApiClients\Cisco;

use App\ApiClients\GenericDeviceApiClient;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Cisco Firepower Threat Defense (FTD) API Client
 *
 * Implements OAuth 2.0 authentication with JWT tokens
 * Documentation: https://developer.cisco.com/docs/ftd-api-reference/
 */
class FtdApiClient extends GenericDeviceApiClient
{
    protected ?string $accessToken = null;
    protected ?string $refreshToken = null;
    protected ?int $tokenExpiry = null;
    protected ?int $refreshTokenExpiry = null;
    protected string $tokenEndpoint = '/api/fdm/v6/fdm/token';

    public function __construct(Device $device)
    {
        parent::__construct($device);

        // Override token endpoint if specified in config
        $customEndpoint = $device->apiConfig?->getValue('token_endpoint');
        if ($customEndpoint) {
            $this->tokenEndpoint = $customEndpoint;
        }
    }

    /**
     * Authenticate and get OAuth access token
     */
    protected function authenticate(): bool
    {
        // Check if we have a valid token
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return true;
        }

        // Try to refresh token if we have one
        if ($this->refreshToken && $this->refreshTokenExpiry && time() < $this->refreshTokenExpiry) {
            if ($this->refreshAccessToken()) {
                return true;
            }
        }

        // Get new token using password grant
        return $this->getPasswordGrantToken();
    }

    /**
     * Get access token using password grant
     */
    protected function getPasswordGrantToken(): bool
    {
        $apiConfig = $this->device->apiConfig;
        if (!$apiConfig) {
            return false;
        }

        $username = $apiConfig->getValue('username');
        $password = $apiConfig->getValue('password');

        if (!$username || !$password) {
            Log::error('FTD API: Missing credentials', ['device_id' => $this->device->device_id]);
            return false;
        }

        try {
            $data = $this->httpClient->post($this->tokenEndpoint, [
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
            ]);

            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                $this->refreshToken = $data['refresh_token'] ?? null;
                $this->tokenExpiry = time() + ($data['expires_in'] ?? 1800) - 60; // 60s buffer
                $this->refreshTokenExpiry = time() + ($data['refresh_expires_in'] ?? 2400) - 60;

                Log::debug('FTD API: Authentication successful', [
                    'device_id' => $this->device->device_id,
                    'expires_in' => $data['expires_in'] ?? null,
                ]);

                return true;
            }

            Log::error('FTD API: No access token in response', [
                'device_id' => $this->device->device_id,
            ]);

            return false;

        } catch (GuzzleException $e) {
            Log::error('FTD API: Authentication failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Refresh access token using refresh token
     */
    protected function refreshAccessToken(): bool
    {
        if (!$this->refreshToken) {
            return false;
        }

        try {
            $data = $this->httpClient->post($this->tokenEndpoint, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
            ]);

            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;
                $this->tokenExpiry = time() + ($data['expires_in'] ?? 1800) - 60;
                $this->refreshTokenExpiry = time() + ($data['refresh_expires_in'] ?? 2400) - 60;

                Log::debug('FTD API: Token refreshed successfully', [
                    'device_id' => $this->device->device_id,
                ]);

                return true;
            }

            return false;

        } catch (GuzzleException $e) {
            Log::debug('FTD API: Token refresh failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Override get() to add OAuth authentication
     */
    public function get(string $path, array $query = []): array
    {
        if (!$this->authenticate()) {
            return [];
        }

        try {
            // Set Bearer token header
            $this->httpClient->setHeader('Authorization', 'Bearer ' . $this->accessToken);

            return $this->httpClient->get($path, $query);

        } catch (\Throwable $e) {
            Log::error('FTD API GET request failed', [
                'device_id' => $this->device->device_id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Override post() to add OAuth authentication
     */
    public function post(string $path, array $body = []): array
    {
        if (!$this->authenticate()) {
            return [];
        }

        try {
            // Set Bearer token header
            $this->httpClient->setHeader('Authorization', 'Bearer ' . $this->accessToken);

            return $this->httpClient->post($path, $body);

        } catch (\Throwable $e) {
            Log::error('FTD API POST request failed', [
                'device_id' => $this->device->device_id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Test connection to FTD device
     */
    public function testConnection(): bool
    {
        return $this->authenticate();
    }

    /**
     * Check if device is reachable via API
     */
    public function isReachable(): bool
    {
        return $this->testConnection();
    }

    /**
     * Check if device is supported
     */
    public function supports(Device $device): bool
    {
        return $device->apiConfig && $device->apiConfig->template?->key === 'cisco_ftd';
    }

    /**
     * Get API information
     */
    public function getApiInfo(): array
    {
        return [
            'vendor' => 'Cisco',
            'api_type' => 'FTD REST API with OAuth 2.0',
            'version' => 'v6',
            'authenticated' => !empty($this->accessToken),
            'token_expires' => $this->tokenExpiry ? date('Y-m-d H:i:s', $this->tokenExpiry) : null,
        ];
    }

    /**
     * Revoke access token on cleanup
     */
    public function __destruct()
    {
        // Optional: Revoke token when done
        // FTD tokens expire automatically, so this is not critical
    }
}
