<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

/**
 * VMware vCenter REST API Client
 *
 * Implements session-based authentication per VMware vCenter API requirements:
 * 1. POST to /com/vmware/cis/session with Basic auth to create session
 * 2. Use vmware-api-session-id header for all subsequent requests
 */
class VCenterClient implements DeviceApiClientInterface
{
    public const VENDOR = 'vmware';

    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected DeviceApiConfig $apiConfig;
    protected ?string $sessionId = null;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('template', 'authSchema')
            ->where('device_id', $device->device_id)
            ->firstOrFail();

        // Build HTTP client options
        $httpOptions = DeviceApiSettings::httpOptions($device);
        if (empty($httpOptions['base_url'])) {
            throw new \RuntimeException("No base URL configured for VMware vCenter device {$device->device_id}");
        }

        // Create HTTP client without auth headers initially
        $this->httpClient = new DeviceHttpClient([
            'base_url' => $httpOptions['base_url'],
            'headers' => $httpOptions['headers'] ?? [],
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $httpOptions['timeout_ms'] ?? 5000,
            'proxy' => $httpOptions['proxy'] ?? null,
        ], $device);

        // Initialize VMware session
        $this->initializeSession();
    }

    /**
     * Create VMware vCenter session and set session ID header
     */
    protected function initializeSession(): void
    {
        $username = $this->apiConfig->getValue('username');
        $password = $this->apiConfig->getValue('password');

        if (!$username || !$password) {
            throw new \RuntimeException("Username and password required for VMware vCenter authentication");
        }

        // Create temporary client with Basic auth for session creation
        $httpOptions = DeviceApiSettings::httpOptions($this->device);
        $tempClient = new DeviceHttpClient([
            'base_url' => $this->httpClient->getBaseUrl(),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$username:$password"),
            ],
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $httpOptions['timeout_ms'] ?? 5000,
        ], $this->device);

        try {
            Log::debug("Creating VMware vCenter session", [
                'device_id' => $this->device->device_id,
                'base_url' => $this->httpClient->getBaseUrl(),
                'username' => $username,
            ]);

            // POST to session endpoint (vCenter 8.x uses /api/session)
            // For vCenter 8.x, the response is a plain string (the session ID)
            $response = $tempClient->post('/api/session', []);

            // vCenter 8.x returns the session ID as a string value
            // It could be in 'value' field or the entire response could be the string
            if (is_string($response)) {
                $this->sessionId = $response;
            } elseif (isset($response['value'])) {
                $this->sessionId = $response['value'];
            } elseif (is_array($response) && count($response) === 1 && isset($response[0])) {
                $this->sessionId = $response[0];
            } else {
                Log::error("Unexpected session response format", [
                    'device_id' => $this->device->device_id,
                    'response_type' => gettype($response),
                    'response' => $response,
                ]);
                throw new \RuntimeException("Failed to get session ID from response: " . json_encode($response));
            }

            if (empty($this->sessionId)) {
                throw new \RuntimeException("Session ID is empty");
            }

            // Set session header for all future requests
            $this->httpClient->setHeader('vmware-api-session-id', $this->sessionId);

            Log::debug("VMware vCenter session created successfully", [
                'device_id' => $this->device->device_id,
                'session_id_length' => strlen($this->sessionId),
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to create VMware vCenter session", [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("VMware vCenter session creation failed: " . $e->getMessage());
        }
    }

    public function supports(Device $device): bool
    {
        // Support VMware vCenter devices with API config
        if (!in_array($device->os, ['vmware', 'vsphere', 'vmware-vcsa'], true)) {
            return false;
        }

        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();
        return $apiConfig !== null;
    }

    public function capabilities(): array
    {
        return ['inventory', 'ports', 'sensors', 'processors', 'mempools'];
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
     * VCenterClient uses template-driven polling via DeviceApiExecutor.
     * These fetch methods are not used - they exist only to satisfy the interface.
     */
    public function fetchSensors(Device $device): array
    {
        return [];
    }

    public function fetchPorts(Device $device): array
    {
        return [];
    }

    public function fetchMempools(Device $device): array
    {
        return [];
    }

    public function fetchProcessors(Device $device): array
    {
        return [];
    }

    public function fetchInventory(Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        try {
            // Try to get cluster info as a simple connectivity test
            $this->get('/vcenter/cluster');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            // Get vCenter API version info
            $response = $this->get('/appliance/system/version');
            return [
                'vendor' => 'vmware',
                'product' => 'vcenter',
                'version' => $response['version'] ?? 'unknown',
                'build' => $response['build'] ?? 'unknown',
                'session_id' => $this->sessionId ? substr($this->sessionId, 0, 8) . '...' : null,
            ];
        } catch (\Throwable $e) {
            return [
                'vendor' => 'vmware',
                'product' => 'vcenter',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
