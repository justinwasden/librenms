<?php

namespace App\ApiClients;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use LibreNMS\Util\DeviceApiSettings;

/**
 * Generic Device API Client
 *
 * Fallback client that works with any template using configured auth schemas.
 * Supports basic auth, bearer tokens, and custom headers.
 */
class GenericDeviceApiClient implements DeviceApiClientInterface
{
    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected DeviceApiConfig $apiConfig;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('template', 'authSchema')
            ->where('device_id', $device->device_id)
            ->firstOrFail();

        // Build HTTP client with auth
        $baseUrl = DeviceApiSettings::getResolvedBaseUrl($device);
        if (!$baseUrl) {
            throw new \RuntimeException("No base URL configured for device {$device->device_id}");
        }

        $headers = $this->buildAuthHeaders();

        $this->httpClient = new DeviceHttpClient([
            'base_url' => $baseUrl,
            'headers' => $headers,
            'verify_tls' => DeviceApiSettings::getVerifyTls($device),
            'timeout_ms' => DeviceApiSettings::getTimeout($device),
        ], $device);
    }

    public function supports(Device $device): bool
    {
        // This is a fallback client - it supports any device with an API config
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();
        return $apiConfig !== null;
    }

    public function capabilities(): array
    {
        // Return capabilities from template
        $capabilities = $this->apiConfig->template->capabilities ?? [];
        return is_array($capabilities) ? $capabilities : json_decode($capabilities, true) ?? [];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->httpClient->get($path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->httpClient->post($path, $body);
    }

    protected function buildAuthHeaders(): array
    {
        $headers = [];
        $schema = $this->apiConfig->authSchema;

        if (!$schema) {
            return $headers;
        }

        switch ($schema->key) {
            case 'basic':
                // Basic authentication
                $username = $this->apiConfig->getValue('username') ?? '';
                $password = $this->apiConfig->getValue('password') ?? '';
                if ($username && $password) {
                    $encoded = base64_encode("$username:$password");
                    $headers['Authorization'] = "Basic $encoded";
                }
                break;

            case 'bearer':
                // Bearer token
                $token = $this->apiConfig->getValue('token') ?? $this->apiConfig->getValue('api_token') ?? '';
                if ($token) {
                    $headers['Authorization'] = "Bearer $token";
                }
                break;

            case 'vmware_vcenter_session':
                // VMware vCenter session-based auth
                // Note: This requires login first to get session token
                // For now, use basic auth and let the auth strategy handle session creation
                $username = $this->apiConfig->getValue('username') ?? '';
                $password = $this->apiConfig->getValue('password') ?? '';
                if ($username && $password) {
                    $encoded = base64_encode("$username:$password");
                    $headers['Authorization'] = "Basic $encoded";
                }
                break;

            case 'custom_header':
                // Custom header auth
                $headerName = $this->apiConfig->getValue('header_name') ?? '';
                $headerValue = $this->apiConfig->getValue('header_value') ?? '';
                if ($headerName && $headerValue) {
                    $headers[$headerName] = $headerValue;
                }
                break;

            default:
                // Try to auto-detect from values
                $token = $this->apiConfig->getValue('token') ?? $this->apiConfig->getValue('api_token');
                if ($token) {
                    $headers['Authorization'] = "Bearer $token";
                } else {
                    $username = $this->apiConfig->getValue('username');
                    $password = $this->apiConfig->getValue('password');
                    if ($username && $password) {
                        $encoded = base64_encode($username . ':' . $password);
                        $headers['Authorization'] = "Basic $encoded";
                    }
                }
                break;
        }

        // Add any custom headers from config
        $customHeaders = $this->apiConfig->getValue('custom_headers');
        if (!empty($customHeaders) && is_array($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        return $headers;
    }
}
