<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * VMware VeloCloud Orchestrator API Client
 *
 * Handles API token-based authentication for VeloCloud Orchestrator REST API
 * Supports both Enterprise and Operator API tokens
 */
class VeloCloudClient implements DeviceApiClientInterface
{
    public const VENDOR = 'vmware';

    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected ?string $apiToken = null;
    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $sessionCookie = null;
    protected ?string $enterpriseId = null;
    protected ?string $edgeId = null;
    protected ?string $edgeLogicalId = null;
    protected bool $useSessionAuth = false;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Read config from device attributes
        $baseUrl = $device->getAttrib('api_base_url');
        $this->apiToken  = $device->getAttrib('api_credential_api_token');
        $this->username  = $device->getAttrib('api_credential_username');
        $this->password  = $device->getAttrib('api_credential_password');
        $this->enterpriseId = $device->getAttrib('api_credential_enterprise_id');
        $this->edgeId = $device->getAttrib('api_credential_edge_id');
        $verifyTls = (bool) $device->getAttrib('api_verify_ssl', true);
        $timeoutMs = (int) $device->getAttrib('api_credential_timeout_ms', 10000);

        if (!$baseUrl) {
            throw new RuntimeException("API config for device {$device->device_id} is missing base_url");
        }

        // Determine authentication method
        if ($this->username && $this->password) {
            // Session-based authentication
            $this->useSessionAuth = true;
            Log::debug('VeloCloudClient using session-based authentication', [
                'device_id' => $this->device->device_id,
                'username' => $this->username,
            ]);
        } elseif ($this->apiToken) {
            // API token authentication
            $this->useSessionAuth = false;
            Log::debug('VeloCloudClient using API token authentication', [
                'device_id' => $this->device->device_id,
                'api_token' => substr($this->apiToken, 0, 8) . '...',
            ]);
        } else {
            throw new RuntimeException("API config for device {$device->device_id} requires either (username + password) or api_token");
        }

        // Strip trailing slash from base_url
        $baseUrl = rtrim($baseUrl, '/');

        // Initialize HTTP client
        $headers = ['Content-Type' => 'application/json'];

        if (!$this->useSessionAuth) {
            // Add Authorization header for token-based auth
            $headers['Authorization'] = 'Token ' . $this->apiToken;
        }

        $this->httpClient = new DeviceHttpClient([
            'base_url'   => $baseUrl,
            'headers'    => $headers,
            'verify_tls' => $verifyTls,
            'timeout_ms' => $timeoutMs,
        ], $device);

        // For session auth, login and get session cookie
        if ($this->useSessionAuth) {
            $this->login();
        }

        Log::debug('VeloCloudClient init config', [
            'device_id'       => $this->device->device_id,
            'base_url'        => $baseUrl,
            'verify_tls'      => $verifyTls,
            'auth_method'     => $this->useSessionAuth ? 'session' : 'token',
            'enterprise_id'   => $this->enterpriseId,
            'edge_id'         => $this->edgeId,
            'monitoring_mode' => $this->edgeId ? 'single_edge' : 'all_edges',
        ]);
    }

    /**
     * Login to VeloCloud and get session cookie
     */
    protected function login(): void
    {
        try {
            $response = $this->httpClient->rawPost('/portal/rest/login/enterpriseLogin', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            $status = (int) ($response['status'] ?? 0);
            if ($status !== 200) {
                throw new RuntimeException("Login failed with status {$status}");
            }

            // Extract session cookie from Set-Cookie header
            $headers = $response['headers'] ?? [];
            $setCookieHeader = null;

            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Set-Cookie') === 0) {
                    $setCookieHeader = is_array($value) ? $value[0] : $value;
                    break;
                }
            }

            if (!$setCookieHeader) {
                throw new RuntimeException("Login succeeded but no session cookie returned");
            }

            // Parse velocloud.session cookie
            if (preg_match('/velocloud\.session=([^;]+)/', $setCookieHeader, $matches)) {
                $this->sessionCookie = $matches[1];

                // Update HTTP client with session cookie
                $this->httpClient = new DeviceHttpClient([
                    'base_url'   => $this->apiConfig->base_url,
                    'headers'    => [
                        'Content-Type' => 'application/json',
                        '_cookies' => ['velocloud.session' => $this->sessionCookie],
                    ],
                    'verify_tls' => (bool) ($this->apiConfig->verify_ssl ?? true),
                    'timeout_ms' => (int) $this->apiConfig->getValue('timeout_ms', 10000),
                ], $this->device);

                Log::debug('VeloCloudClient login successful', [
                    'device_id' => $this->device->device_id,
                    'username' => $this->username,
                ]);
            } else {
                throw new RuntimeException("Failed to parse session cookie from response");
            }
        } catch (\Throwable $e) {
            Log::error('VeloCloudClient login failed', [
                'device_id' => $this->device->device_id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("VeloCloud login failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Prepend the portal/rest path to the endpoint
     */
    protected function getFullApiPath(string $path): string
    {
        $path = ltrim($path, '/');

        // If path already starts with portal/rest, don't prepend
        if (str_starts_with($path, 'portal/rest/')) {
            return '/' . $path;
        }

        return '/portal/rest/' . $path;
    }

    /**
     * Filter edge data to return only the configured edge
     *
     * @param array $data Response data containing edges
     * @return array Filtered data
     */
    protected function filterByEdgeId(array $data): array
    {
        // If no edge_id configured, return all data
        if (!$this->edgeId) {
            return $data;
        }

        // Handle different response formats
        $edgeId = (int) $this->edgeId;

        Log::debug('VeloCloudClient filtering by edge_id', [
            'device_id' => $this->device->device_id,
            'edge_id' => $edgeId,
            'data_keys' => array_keys($data),
            'first_item_keys' => isset($data[0]) ? array_keys($data[0]) : null,
            'has_error' => isset($data['error']),
            'error_message' => $data['error']['message'] ?? null,
        ]);

        // Check for JSON-RPC error response
        if (isset($data['error'])) {
            Log::warning('VeloCloudClient API returned error', [
                'device_id' => $this->device->device_id,
                'error' => $data['error'],
            ]);
            return $data; // Return error as-is for debugging
        }

        // Configuration stack responses (getEdgeConfigurationStack) should NOT be filtered
        // Format: array of config stacks with 'modules' array
        if (isset($data[0]['modules']) && is_array($data[0]['modules'])) {
            Log::debug('VeloCloudClient: Configuration stack detected - no filtering applied');
            return $data;
        }

        // If data is directly an array of edges
        if (isset($data[0]['id']) || isset($data[0]['logicalId'])) {
            Log::debug('VeloCloudClient: Filtering direct array (edges)', [
                'edge_ids_in_response' => array_map(fn($e) => $e['id'] ?? $e['logicalId'] ?? null, $data),
            ]);

            $filtered = array_filter($data, function($edge) use ($edgeId) {
                return ($edge['id'] ?? null) === $edgeId ||
                       ($edge['logicalId'] ?? null) === $edgeId;
            });

            Log::debug('VeloCloudClient: Filtered result', [
                'original_count' => count($data),
                'filtered_count' => count($filtered),
            ]);

            return array_values($filtered);
        }

        // If data is an array of link metrics (from getAggregateEdgeLinkMetrics)
        if (isset($data[0]['linkLogicalId']) || isset($data[0]['edgeLogicalId'])) {
            // Get or fetch the edge's logical ID
            if (!$this->edgeLogicalId) {
                // Fetch it once and cache it
                try {
                    $edges = $this->httpClient->post('/portal/rest/enterprise/getEnterpriseEdges', [
                        'enterpriseId' => (int) $this->enterpriseId,
                    ]);

                    foreach ($edges as $edge) {
                        if (($edge['id'] ?? null) === $edgeId) {
                            $this->edgeLogicalId = $edge['logicalId'] ?? null;
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('VeloCloudClient: Failed to get edge logical ID', [
                        'device_id' => $this->device->device_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($this->edgeLogicalId) {
                Log::debug('VeloCloudClient: Filtering link metrics by edgeLogicalId', [
                    'edge_id' => $edgeId,
                    'edge_logical_id' => $this->edgeLogicalId,
                    'total_links' => count($data),
                ]);

                $filtered = array_filter($data, function($link) {
                    return ($link['edgeLogicalId'] ?? null) === $this->edgeLogicalId;
                });

                Log::debug('VeloCloudClient: Filtered link metrics', [
                    'original_count' => count($data),
                    'filtered_count' => count($filtered),
                ]);

                return array_values($filtered);
            } else {
                Log::warning('VeloCloudClient: Could not determine edge logical ID for filtering link metrics');
                // Return empty array since we can't filter without the logical ID
                return [];
            }
        }

        // If data has a 'data' wrapper
        if (isset($data['data']) && is_array($data['data'])) {
            Log::debug('VeloCloudClient: Filtering data wrapper', [
                'edge_ids_in_response' => array_map(fn($e) => $e['id'] ?? $e['logicalId'] ?? null, $data['data']),
            ]);

            $filtered = array_filter($data['data'], function($edge) use ($edgeId) {
                return ($edge['id'] ?? null) === $edgeId ||
                       ($edge['logicalId'] ?? null) === $edgeId;
            });

            Log::debug('VeloCloudClient: Filtered result', [
                'original_count' => count($data['data']),
                'filtered_count' => count($filtered),
            ]);

            $data['data'] = array_values($filtered);
            return $data;
        }

        Log::debug('VeloCloudClient: No filtering applied - unrecognized format');
        return $data;
    }

    // -------- DeviceApiClientInterface required methods --------

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function supports(Device $device): bool
    {
        return in_array($device->os, ['velocloud', 'vmware-sdwan'], true)
            && ($device->apiConfig !== null || $this->apiConfig !== null);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function capabilities(): array
    {
        return ['device_info', 'inventory', 'ports', 'ipv4', 'sensors', 'mempools', 'processors', 'vlans'];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchSensors(Device $device): array
    {
        // Sensors are handled through the normalizer from getAggregateEdgeLinkMetrics
        // This method is called directly by legacy polling code
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchPorts(Device $device): array
    {
        // Ports are handled through the normalizer from getAggregateEdgeLinkMetrics
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchMempools(Device $device): array
    {
        // Memory pools handled through the normalizer
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchProcessors(Device $device): array
    {
        // Processors handled through the normalizer
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchInventory(Device $device): array
    {
        // Inventory handled through the normalizer from getEnterpriseEdges
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchStorage(Device $device): array
    {
        // VeloCloud doesn't expose storage metrics
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchTransceivers(Device $device): array
    {
        // VeloCloud doesn't expose transceiver metrics
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        // IPv4 addresses handled through the normalizer from getEnterpriseEdges
        return [];
    }

    /**
     * Fetch VLANs from VeloCloud segments
     */
    public function fetchVlans(Device $device): array
    {
        // VLANs handled through the normalizer from getEnterpriseEdges
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchPortsStatistics(Device $device): array
    {
        // Port statistics handled through the normalizer from getAggregateEdgeLinkMetrics
        return [];
    }

    /**
     * Fetch VMs - VeloCloud doesn't have VMs
     */
    public function fetchVms(Device $device): array
    {
        // VeloCloud edges are not VMs
        return [];
    }

    /**
     * Fetch clusters - VeloCloud doesn't have cluster concept
     */
    public function fetchClusters(Device $device): array
    {
        // VeloCloud doesn't have clusters
        return [];
    }

    /**
     * Fetch hosts - VeloCloud doesn't expose hosts
     */
    public function fetchHosts(Device $device): array
    {
        // VeloCloud doesn't expose hypervisor hosts
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function get(string $path, array $query = []): array
    {
        // VeloCloud API uses POST for most operations, but support GET for compatibility
        $fullPath = $this->getFullApiPath($path);
        $response = $this->httpClient->get($fullPath, $query);

        // Filter by edge_id if configured
        return $this->filterByEdgeId($response);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function post(string $path, array $body = []): array
    {
        $fullPath = $this->getFullApiPath($path);

        // Add enterprise ID to request body if configured (for operator tokens)
        if ($this->enterpriseId && !isset($body['enterpriseId'])) {
            $body['enterpriseId'] = (int) $this->enterpriseId;
        }

        // Add edge ID to request body if configured (for per-edge monitoring)
        if ($this->edgeId && !isset($body['edgeId'])) {
            $body['edgeId'] = (int) $this->edgeId;
        }

        $response = $this->httpClient->post($fullPath, $body);

        // Filter response by edge_id if configured
        return $this->filterByEdgeId($response);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function isReachable(): bool
    {
        try {
            // Try to call a lightweight endpoint to check reachability
            $raw = $this->httpClient->rawPost('/portal/rest/enterprise/getEnterprises', []);
            $status = (int) ($raw['status'] ?? 0);

            // Log the raw response for debugging
            Log::debug('VeloCloudClient reachability check response', [
                'device_id' => $this->device->device_id,
                'status' => $status,
                'body_preview' => substr($raw['body'] ?? '', 0, 500),
                'headers' => $raw['headers'] ?? [],
            ]);

            // Check if we got HTML instead of JSON (common auth failure symptom)
            $body = $raw['body'] ?? '';
            if ($status === 401 || $status === 403) {
                Log::warning('VeloCloudClient authentication failed', [
                    'device_id' => $this->device->device_id,
                    'status' => $status,
                    'response_preview' => substr($body, 0, 200),
                ]);
                return false;
            }

            if (str_starts_with(trim($body), '<')) {
                Log::warning('VeloCloudClient received HTML response (possible auth failure)', [
                    'device_id' => $this->device->device_id,
                    'status' => $status,
                    'body_preview' => substr($body, 0, 200),
                ]);
                return false;
            }

            return $status < 500;
        } catch (\Throwable $e) {
            Log::warning('VeloCloudClient reachability check failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function getApiInfo(): array
    {
        try {
            $info = [
                'vendor'      => self::VENDOR,
                'base_url'    => $this->apiConfig->base_url ?? null,
                'api_version' => null,
                'version'     => null,
            ];

            try {
                // Try to get orchestrator version
                $resp = $this->post('enterprise/getEnterprises', []);
                if (is_array($resp) && !empty($resp)) {
                    $info['version'] = 'VeloCloud Orchestrator';
                }
            } catch (\Throwable $e) {
                Log::debug('VeloCloudClient getApiInfo version probe failed', ['error' => $e->getMessage()]);
            }

            return $info;
        } catch (\Throwable $e) {
            Log::error('VeloCloudClient getApiInfo failed', ['error' => $e->getMessage()]);
            return [
                'vendor'      => self::VENDOR,
                'base_url'    => $this->apiConfig->base_url ?? null,
                'api_version' => null,
                'version'     => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
}
