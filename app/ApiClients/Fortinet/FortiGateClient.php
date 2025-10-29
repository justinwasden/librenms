<?php

namespace App\ApiClients\Fortinet;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

/**
 * Fortinet FortiGate REST API Client
 *
 * Supports API token authentication via Bearer token
 */
class FortiGateClient implements DeviceApiClientInterface
{
    public const VENDOR = 'fortinet';

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

        // Build HTTP client with API token auth
        $httpOptions = DeviceApiSettings::httpOptions($device);
        if (empty($httpOptions['base_url'])) {
            throw new \RuntimeException("No base URL configured for FortiGate device {$device->device_id}");
        }

        // Get API token from config
        $apiToken = $this->apiConfig->getValue('api_token') ?? $this->apiConfig->getValue('token');
        if (!$apiToken) {
            throw new \RuntimeException("API token required for FortiGate authentication");
        }

        // Create HTTP client with Bearer token
        $this->httpClient = new DeviceHttpClient([
            'base_url' => $httpOptions['base_url'],
            'headers' => array_merge($httpOptions['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $apiToken,
            ]),
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $httpOptions['timeout_ms'] ?? 5000,
            'proxy' => $httpOptions['proxy'] ?? null,
        ], $device);

        Log::debug("FortiGate client initialized", [
            'device_id' => $device->device_id,
            'base_url' => $httpOptions['base_url'],
        ]);
    }

    public function supports(Device $device): bool
    {
        // Support FortiGate devices with API config
        if (!in_array($device->os, ['fortigate', 'fortinet'], true)) {
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
     * FortiGateClient uses template-driven polling via DeviceApiExecutor.
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
            // Try to get system status
            $this->get('/api/v2/monitor/system/status');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            // Get FortiGate system info
            $response = $this->get('/api/v2/monitor/system/status');
            return [
                'vendor' => 'fortinet',
                'product' => 'fortigate',
                'version' => $response['version'] ?? 'unknown',
                'serial' => $response['serial'] ?? 'unknown',
                'hostname' => $response['hostname'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            return [
                'vendor' => 'fortinet',
                'product' => 'fortigate',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
