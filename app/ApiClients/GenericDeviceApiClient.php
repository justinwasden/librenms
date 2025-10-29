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

        // Initialize session-based auth if needed
        if ($this->apiConfig->authSchema?->key === 'vmware_vcenter_session') {
            $this->initializeVmwareSession();
        }
    }

    /**
     * Initialize VMware vCenter session authentication
     * Creates a session and updates the HTTP client headers with the session ID
     */
    protected function initializeVmwareSession(): void
    {
        $username = $this->apiConfig->getValue('username');
        $password = $this->apiConfig->getValue('password');

        if (!$username || !$password) {
            throw new \RuntimeException("Username and password required for VMware vCenter session auth");
        }

        // Create a temporary HTTP client with Basic auth for session creation
        $tempClient = new DeviceHttpClient([
            'base_url' => $this->httpClient->getBaseUrl(),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$username:$password"),
            ],
            'verify_tls' => DeviceApiSettings::getVerifyTls($this->device),
            'timeout_ms' => DeviceApiSettings::getTimeout($this->device),
        ], $this->device);

        try {
            // POST to session endpoint to create session
            $response = $tempClient->post('/com/vmware/cis/session', []);

            // Extract session ID from response
            $sessionId = $response['value'] ?? $response['session_id'] ?? null;

            if (!$sessionId) {
                throw new \RuntimeException("Failed to get session ID from VMware vCenter response");
            }

            // Update main HTTP client with session header
            $this->httpClient->setHeader('vmware-api-session-id', $sessionId);

            \Illuminate\Support\Facades\Log::debug("VMware vCenter session created", [
                'device_id' => $this->device->device_id,
                'session_id_length' => strlen($sessionId),
            ]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to create VMware vCenter session", [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("VMware vCenter session creation failed: " . $e->getMessage());
        }
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

    /**
     * GenericDeviceApiClient uses template-driven polling via DeviceApiExecutor.
     * These fetch methods are not used - they exist only to satisfy the interface.
     */
    public function fetchSensors(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchPorts(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchMempools(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchProcessors(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchInventory(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(\App\Models\Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        try {
            // Try a simple GET to the base URL
            $this->httpClient->get('/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => 'generic',
            'client' => 'GenericDeviceApiClient',
            'template_key' => $this->apiConfig->template?->key ?? null,
        ];
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
                // Session is initialized in constructor via initializeVmwareSession()
                // No auth headers needed here - session ID is set after login
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
