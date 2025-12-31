<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;
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
    protected string $baseUrl;
    protected bool $verifyTls;
    protected int $timeoutMs;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Read config from device attributes (decrypt credentials as needed)
        $this->baseUrl = $device->getAttrib('api_base_url') ?? '';
        $this->apiToken  = DeviceApiSettings::getCredential($device, 'api_credential_api_token');
        $this->username  = DeviceApiSettings::getCredential($device, 'api_credential_username');
        $this->password  = DeviceApiSettings::getCredential($device, 'api_credential_password');
        $this->enterpriseId = $device->getAttrib('api_credential_enterprise_id');
        $this->edgeId = $device->getAttrib('api_credential_edge_id');
        $this->verifyTls = (bool) $device->getAttrib('api_verify_ssl', true);
        $this->timeoutMs = (int) $device->getAttrib('api_credential_timeout_ms', 10000);

        if (!$this->baseUrl) {
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
        $this->baseUrl = rtrim($this->baseUrl, '/');

        // Initialize HTTP client
        $headers = ['Content-Type' => 'application/json'];

        if (!$this->useSessionAuth) {
            // Add Authorization header for token-based auth
            $headers['Authorization'] = 'Token ' . $this->apiToken;
        }

        $this->httpClient = new DeviceHttpClient([
            'base_url'   => $this->baseUrl,
            'headers'    => $headers,
            'verify_tls' => $this->verifyTls,
            'timeout_ms' => $this->timeoutMs,
        ], $device);

        // For session auth, login and get session cookie
        if ($this->useSessionAuth) {
            $this->login();
        }

        Log::debug('VeloCloudClient init config', [
            'device_id'       => $this->device->device_id,
            'base_url'        => $this->baseUrl,
            'verify_tls'      => $this->verifyTls,
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
                    'base_url'   => $this->baseUrl,
                    'headers'    => [
                        'Content-Type' => 'application/json',
                        '_cookies' => ['velocloud.session' => $this->sessionCookie],
                    ],
                    'verify_tls' => $this->verifyTls,
                    'timeout_ms' => $this->timeoutMs,
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
            && !empty($device->getAttrib('api_base_url'));
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function capabilities(): array
    {
        return ['device_info', 'inventory', 'ports', 'ipv4', 'sensors', 'mempools', 'processors', 'vlans', 'ports_stats'];
    }

    /**
     * Fetch sensors from VeloCloud link metrics
     * Returns: latency, jitter, packet loss, bandwidth utilization, link state
     */
    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            // Get link metrics from aggregate endpoint
            $linkMetrics = $this->getAggregateLinkMetrics();

            foreach ($linkMetrics as $link) {
                $linkName = $link['name'] ?? $link['link']['displayName'] ?? 'Link';
                $linkId = $link['linkId'] ?? 0;

                // Link state sensor
                if (isset($link['state'])) {
                    $stateMap = [
                        'STABLE' => 2,
                        'UP' => 2,
                        'UNSTABLE' => 1,
                        'DOWN' => 0,
                        'DEAD' => 0,
                    ];
                    $state = strtoupper($link['state']);
                    $stateValue = $stateMap[$state] ?? 3;

                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'velocloud',
                        'sensor_descr' => "{$linkName} State",
                        'sensor_index' => "link-{$linkId}-state",
                        'sensor_current' => $stateValue,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'down'],
                            ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unstable'],
                            ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'stable'],
                            ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                        ],
                    ];
                }

                // Packet loss percentage
                if (isset($link['bestLossPercentage'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'velocloud',
                        'sensor_descr' => "{$linkName} Packet Loss",
                        'sensor_index' => "link-{$linkId}-loss",
                        'sensor_current' => round($link['bestLossPercentage'], 2),
                        'sensor_limit' => 5,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Latency (ms)
                if (isset($link['bestLatencyMsec'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'velocloud',
                        'sensor_descr' => "{$linkName} Latency",
                        'sensor_index' => "link-{$linkId}-latency",
                        'sensor_current' => $link['bestLatencyMsec'],
                        'sensor_limit' => 150,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Jitter (ms)
                if (isset($link['bestJitterMsec'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'velocloud',
                        'sensor_descr' => "{$linkName} Jitter",
                        'sensor_index' => "link-{$linkId}-jitter",
                        'sensor_current' => $link['bestJitterMsec'],
                        'sensor_limit' => 30,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Bandwidth utilization (RX and TX)
                if (isset($link['bpsOfBestPathRx']) && isset($link['link']['bandwidthRx'])) {
                    $bwRx = $link['link']['bandwidthRx'] * 1000000; // Mbps to bps
                    if ($bwRx > 0) {
                        $utilRx = round(($link['bpsOfBestPathRx'] / $bwRx) * 100, 2);
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'velocloud',
                            'sensor_descr' => "{$linkName} RX Utilization",
                            'sensor_index' => "link-{$linkId}-rx-util",
                            'sensor_current' => min($utilRx, 100),
                            'sensor_limit' => 90,
                            'sensor_limit_low' => 0,
                        ];
                    }
                }

                if (isset($link['bpsOfBestPathTx']) && isset($link['link']['bandwidthTx'])) {
                    $bwTx = $link['link']['bandwidthTx'] * 1000000;
                    if ($bwTx > 0) {
                        $utilTx = round(($link['bpsOfBestPathTx'] / $bwTx) * 100, 2);
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'velocloud',
                            'sensor_descr' => "{$linkName} TX Utilization",
                            'sensor_index' => "link-{$linkId}-tx-util",
                            'sensor_current' => min($utilTx, 100),
                            'sensor_limit' => 90,
                            'sensor_limit_low' => 0,
                        ];
                    }
                }

                // Score (0-10)
                if (isset($link['bestScore'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'velocloud',
                        'sensor_descr' => "{$linkName} Quality Score",
                        'sensor_index' => "link-{$linkId}-score",
                        'sensor_current' => round($link['bestScore'], 1),
                        'sensor_limit' => 10,
                        'sensor_limit_low' => 0,
                        'sensor_limit_warn' => 7,
                    ];
                }
            }

            // Get edge system metrics
            $edgeInfo = $this->getEdgeInfo();
            if (!empty($edgeInfo)) {
                // CPU usage
                if (isset($edgeInfo['systemCpuPercent'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'velocloud-system',
                        'sensor_descr' => 'System CPU',
                        'sensor_index' => 'system-cpu',
                        'sensor_current' => $edgeInfo['systemCpuPercent'],
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Memory usage
                if (isset($edgeInfo['systemMemoryPercent'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'velocloud-system',
                        'sensor_descr' => 'System Memory',
                        'sensor_index' => 'system-memory',
                        'sensor_current' => $edgeInfo['systemMemoryPercent'],
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Flow count
                if (isset($edgeInfo['flowCount'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'velocloud-system',
                        'sensor_descr' => 'Active Flows',
                        'sensor_index' => 'flow-count',
                        'sensor_current' => $edgeInfo['flowCount'],
                    ];
                }

                // Tunnel count
                if (isset($edgeInfo['tunnelCount'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'velocloud-system',
                        'sensor_descr' => 'Active Tunnels',
                        'sensor_index' => 'tunnel-count',
                        'sensor_current' => $edgeInfo['tunnelCount'],
                    ];
                }
            }

            Log::debug('VeloCloud: Fetched sensors', [
                'device_id' => $this->device->device_id,
                'count' => count($sensors),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchSensors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    /**
     * Fetch ports/links from VeloCloud
     */
    public function fetchPorts(Device $device): array
    {
        $ports = [];

        try {
            $linkMetrics = $this->getAggregateLinkMetrics();
            $ifIndex = 1;

            foreach ($linkMetrics as $link) {
                $linkName = $link['name'] ?? "Link-{$ifIndex}";
                $linkInfo = $link['link'] ?? [];
                $state = $link['state'] ?? 'UNKNOWN';

                // Map VeloCloud states to standard operational status
                $operStatus = match (strtoupper($state)) {
                    'STABLE', 'UP' => 'up',
                    'DOWN', 'DEAD' => 'down',
                    'UNSTABLE' => 'testing',
                    default => 'unknown',
                };

                $adminStatus = ($link['serviceState'] ?? 'IN_SERVICE') === 'IN_SERVICE' ? 'up' : 'down';

                // Calculate speed from bandwidth config or best path
                $speed = 0;
                if (isset($link['bpsOfBestPathTx']) && isset($link['bpsOfBestPathRx'])) {
                    $speed = max($link['bpsOfBestPathTx'], $link['bpsOfBestPathRx']);
                } elseif (isset($linkInfo['bandwidthTx'])) {
                    $speed = $linkInfo['bandwidthTx'] * 1000000;
                }

                // Build interface alias with ISP and IP info
                $displayName = $linkInfo['displayName'] ?? $link['displayName'] ?? null;
                $isp = $linkInfo['isp'] ?? null;
                $linkIp = $linkInfo['linkIpAddress'] ?? null;

                $labelParts = [];
                if ($displayName && $displayName !== $linkName) {
                    $labelParts[] = $displayName;
                } elseif ($isp) {
                    $labelParts[] = $isp;
                }
                if ($linkIp) {
                    $labelParts[] = $linkIp;
                }
                $ifAlias = !empty($labelParts) ? implode(' - ', $labelParts) : $linkName;

                $ports[] = [
                    'ifIndex' => $ifIndex++,
                    'ifName' => $linkName,
                    'ifDescr' => $linkInfo['interface'] ?? $linkName,
                    'ifType' => 'ethernetCsmacd',
                    'ifOperStatus' => $operStatus,
                    'ifAdminStatus' => $adminStatus,
                    'ifSpeed' => $speed,
                    'ifMtu' => 1500,
                    'ifPhysAddress' => $linkInfo['macAddress'] ?? '',
                    'ifAlias' => $ifAlias,
                ];
            }

            Log::debug('VeloCloud: Fetched ports', [
                'device_id' => $this->device->device_id,
                'count' => count($ports),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchPorts failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Fetch memory pools from VeloCloud edge
     */
    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        try {
            $edgeInfo = $this->getEdgeInfo();

            if (!empty($edgeInfo)) {
                // System memory from percentage
                $memPercent = $edgeInfo['systemMemoryPercent'] ?? null;
                $memTotal = $edgeInfo['memoryTotal'] ?? 0;
                $memUsed = $edgeInfo['memoryUsed'] ?? 0;

                if ($memPercent !== null || $memTotal > 0) {
                    $mempools[] = [
                        'mempool_index' => 0,
                        'mempool_type' => 'velocloud',
                        'mempool_descr' => 'System Memory',
                        'mempool_total' => $memTotal ?: 100,
                        'mempool_used' => $memUsed ?: ($memPercent ?? 0),
                        'mempool_free' => $memTotal > 0 ? ($memTotal - $memUsed) : (100 - ($memPercent ?? 0)),
                        'mempool_perc' => $memPercent ?? ($memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0),
                    ];
                }
            }

            Log::debug('VeloCloud: Fetched mempools', [
                'device_id' => $this->device->device_id,
                'count' => count($mempools),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchMempools failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    /**
     * Fetch processors from VeloCloud edge
     */
    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        try {
            $edgeInfo = $this->getEdgeInfo();

            if (!empty($edgeInfo) && isset($edgeInfo['systemCpuPercent'])) {
                $processors[] = [
                    'processor_index' => 0,
                    'processor_type' => 'velocloud',
                    'processor_descr' => 'System CPU',
                    'processor_usage' => $edgeInfo['systemCpuPercent'],
                ];
            }

            Log::debug('VeloCloud: Fetched processors', [
                'device_id' => $this->device->device_id,
                'count' => count($processors),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchProcessors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    /**
     * Fetch inventory from VeloCloud edge
     */
    public function fetchInventory(Device $device): array
    {
        $inventory = [];

        try {
            $edgeInfo = $this->getEdgeInfo();

            if (!empty($edgeInfo)) {
                $edgeName = $edgeInfo['name'] ?? $edgeInfo['hostname'] ?? 'VeloCloud Edge';
                $state = $edgeInfo['edgeState'] ?? 'UNKNOWN';

                $inventory[] = [
                    'entPhysicalIndex' => 1,
                    'entPhysicalDescr' => "VeloCloud Edge: {$edgeName} [{$state}]",
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => $edgeName,
                    'entPhysicalModelName' => $edgeInfo['modelNumber'] ?? 'VeloCloud Edge',
                    'entPhysicalSerialNum' => $edgeInfo['serialNumber'] ?? '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'VMware',
                    'entPhysicalHardwareRev' => $edgeInfo['buildNumber'] ?? '',
                    'entPhysicalFirmwareRev' => $edgeInfo['softwareVersion'] ?? '',
                    'entPhysicalSoftwareRev' => $edgeInfo['softwareVersion'] ?? '',
                ];
            }

            Log::debug('VeloCloud: Fetched inventory', [
                'device_id' => $this->device->device_id,
                'count' => count($inventory),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchInventory failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    /**
     * Fetch storage - VeloCloud doesn't expose storage metrics
     */
    public function fetchStorage(Device $device): array
    {
        return [];
    }

    /**
     * Fetch transceivers - VeloCloud doesn't expose transceiver metrics
     */
    public function fetchTransceivers(Device $device): array
    {
        return [];
    }

    /**
     * Fetch IPv4 addresses from VeloCloud edge
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        $addresses = [];

        try {
            $linkMetrics = $this->getAggregateLinkMetrics();

            foreach ($linkMetrics as $link) {
                $linkInfo = $link['link'] ?? [];
                $linkName = $link['name'] ?? 'link';

                // Get IP address from link
                $ip = $linkInfo['linkIpAddress'] ?? null;
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[] = [
                        'ipv4_address' => $ip,
                        'ipv4_prefixlen' => 24, // VeloCloud doesn't expose subnet
                        'ifName' => $linkName,
                    ];
                }

                // Get gateway IP
                $gateway = $linkInfo['gatewayIpAddress'] ?? $linkInfo['nextHopIpAddress'] ?? null;
                if ($gateway && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Store gateway info in extra field
                }
            }

            // Get LAN IPs from edge configuration
            $edgeConfig = $this->getEdgeConfiguration();
            if (!empty($edgeConfig['lan'])) {
                foreach ($edgeConfig['lan'] as $lan) {
                    $ip = $lan['ipAddress'] ?? null;
                    $prefix = $lan['prefixLength'] ?? $lan['cidrPrefix'] ?? 24;
                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $addresses[] = [
                            'ipv4_address' => $ip,
                            'ipv4_prefixlen' => $prefix,
                            'ifName' => $lan['name'] ?? 'LAN',
                        ];
                    }
                }
            }

            Log::debug('VeloCloud: Fetched IPv4 addresses', [
                'device_id' => $this->device->device_id,
                'count' => count($addresses),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchIpv4Addresses failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    /**
     * Fetch VLANs from VeloCloud segments
     */
    public function fetchVlans(Device $device): array
    {
        $vlans = [];

        try {
            $edgeConfig = $this->getEdgeConfiguration();

            // Get segments (VeloCloud's equivalent of VLANs)
            $segments = $edgeConfig['segments'] ?? [];
            foreach ($segments as $segment) {
                $vlanId = $segment['vlanId'] ?? $segment['segment']['segmentId'] ?? null;
                if ($vlanId !== null) {
                    $vlans[] = [
                        'vlan_vlan' => $vlanId,
                        'vlan_domain' => $segment['segment']['name'] ?? "Segment {$vlanId}",
                        'vlan_name' => $segment['name'] ?? $segment['segment']['name'] ?? "VLAN {$vlanId}",
                        'vlan_type' => 'ethernet',
                        'vlan_mtu' => 1500,
                    ];
                }
            }

            Log::debug('VeloCloud: Fetched VLANs', [
                'device_id' => $this->device->device_id,
                'count' => count($vlans),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchVlans failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vlans;
    }

    /**
     * Fetch port statistics from VeloCloud link metrics
     */
    public function fetchPortsStatistics(Device $device): array
    {
        $stats = [];

        try {
            $linkMetrics = $this->getAggregateLinkMetrics();

            foreach ($linkMetrics as $link) {
                $linkName = $link['name'] ?? 'Link';

                $stats[] = [
                    'ifName' => $linkName,
                    'ifInOctets' => $link['bytesRx'] ?? 0,
                    'ifOutOctets' => $link['bytesTx'] ?? 0,
                    'ifInUcastPkts' => $link['packetsRx'] ?? 0,
                    'ifOutUcastPkts' => $link['packetsTx'] ?? 0,
                    'ifInErrors' => 0,
                    'ifOutErrors' => 0,
                    'ifInDiscards' => $link['packetsDropRx'] ?? 0,
                    'ifOutDiscards' => $link['packetsDropTx'] ?? 0,
                ];
            }

            Log::debug('VeloCloud: Fetched port statistics', [
                'device_id' => $this->device->device_id,
                'count' => count($stats),
            ]);

        } catch (\Throwable $e) {
            Log::error('VeloCloud fetchPortsStatistics failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Fetch VMs - VeloCloud doesn't have VMs
     */
    public function fetchVms(Device $device): array
    {
        return [];
    }

    /**
     * Fetch clusters - VeloCloud doesn't have cluster concept
     */
    public function fetchClusters(Device $device): array
    {
        return [];
    }

    /**
     * Fetch hosts - VeloCloud doesn't expose hosts
     */
    public function fetchHosts(Device $device): array
    {
        return [];
    }

    // -------- VeloCloud-specific API methods --------

    /**
     * Get aggregate link metrics for the edge
     */
    protected function getAggregateLinkMetrics(): array
    {
        try {
            $body = [
                'interval' => [
                    'start' => time() - 300, // Last 5 minutes
                    'end' => time(),
                ],
            ];

            if ($this->edgeId) {
                $body['edgeId'] = (int) $this->edgeId;
            }

            $response = $this->post('metrics/getAggregateEdgeLinkMetrics', $body);

            return $response ?? [];
        } catch (\Throwable $e) {
            Log::warning('VeloCloud getAggregateLinkMetrics failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get edge information
     */
    protected function getEdgeInfo(): array
    {
        try {
            if (!$this->edgeId) {
                return [];
            }

            $response = $this->post('edge/getEdge', [
                'edgeId' => (int) $this->edgeId,
                'with' => ['links', 'site', 'configuration'],
            ]);

            return $response ?? [];
        } catch (\Throwable $e) {
            Log::warning('VeloCloud getEdgeInfo failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get edge configuration
     */
    protected function getEdgeConfiguration(): array
    {
        try {
            if (!$this->edgeId) {
                return [];
            }

            $response = $this->post('edge/getEdgeConfigurationStack', [
                'edgeId' => (int) $this->edgeId,
            ]);

            // Parse configuration modules
            $config = [];
            foreach ($response ?? [] as $stack) {
                foreach ($stack['modules'] ?? [] as $module) {
                    $moduleName = $module['name'] ?? '';
                    if (isset($module['data'])) {
                        $config[$moduleName] = $module['data'];
                    }
                }
            }

            return $config;
        } catch (\Throwable $e) {
            Log::warning('VeloCloud getEdgeConfiguration failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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
                'base_url'    => $this->baseUrl,
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
                'base_url'    => $this->baseUrl,
                'api_version' => null,
                'version'     => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
}
