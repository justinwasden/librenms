<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * VMware vCenter REST API Client (Supports v7.x and v8.x ONLY)
 */
class VCenterClient implements DeviceApiClientInterface
{
    public const VENDOR = 'vmware';

    public ?string $sessionId = null;

    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected ?DeviceApiConfig $apiConfig = null;
    protected string $apiRoot = '/api'; // Hardcoded for v7.x and v8.x

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load saved API config from relation or DB
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        if (!$this->apiConfig) {
            throw new RuntimeException("No saved API configuration found for device {$device->device_id}");
        }

        // Use model columns for base_url/verify_ssl and values for other fields
        $baseUrl   = $this->apiConfig->base_url;
        $username  = $this->apiConfig->getValue('username');
        $password  = $this->apiConfig->getValue('password');
        $verifyTls = (bool) ($this->apiConfig->verify_ssl ?? true); // Captures the user setting
        $timeoutMs = (int) $this->apiConfig->getValue('timeout_ms', 5000);

        // Mask password for logs
        $maskedPassword = $password !== null ? str_repeat('*', max(4, strlen($password))) : null;

        if (!$baseUrl) {
            throw new RuntimeException("API config for device {$device->device_id} is missing base_url");
        }

        // Strip /api suffix from base_url if present (client adds /api/ prefix itself)
        $baseUrl = rtrim($baseUrl, '/');
        if (str_ends_with($baseUrl, '/api')) {
            $baseUrl = substr($baseUrl, 0, -4);
        }

        // Initialize HTTP client using the DB's base_url for ALL requests
        $headers = $this->apiConfig->extra_headers ?? [];
        if ($username && $password) {
            $headers['Authorization'] = 'Basic ' . base64_encode("$username:$password");
        }

        // --- CRITICAL FIX: Set TLS options and honor user's verify setting ---
        // Allow TLS 1.2+ for better compatibility
        $curlOptions = [
            // Allow TLS 1.2 or higher (more flexible than forcing only 1.2)
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_DEFAULT,
            // Honors the 'Verify TLS/SSL certificates' user setting
            CURLOPT_SSL_VERIFYPEER => (int) $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ];

        $this->httpClient = new DeviceHttpClient([
            'base_url'   => $baseUrl,
            'headers'    => $headers,
            'verify_tls' => $verifyTls,
            'timeout_ms' => $timeoutMs,
            'curl_opts'  => $curlOptions,
        ], $device);

        Log::debug('VCenterClient init config', [
            'device_id'       => $this->device->device_id,
            'base_url'        => $baseUrl,
            'verify_tls_user' => $verifyTls,
            'username'        => $username,
            'password'        => $maskedPassword,
            'curl_opts'       => array_keys($curlOptions),
        ]);


        // Initialize VMware session automatically
        $this->initializeSession();

        // Set the session ID header for all subsequent calls
        if ($this->sessionId) {
            $this->httpClient->setHeader('vmware-api-session-id', $this->sessionId);
            // Remove Basic Auth header as it's no longer needed for subsequent calls
            $this->httpClient->unsetHeader('Authorization');
        }
    }

    /**
     * Create VMware vCenter session and set session ID header
     */
    protected function initializeSession(): void
    {
        $sessionEndpoint = '/api/session';

        Log::debug('VCenterClient attempting session creation', ['device_id' => $this->device->device_id]);

        try {
            $raw = $this->httpClient->rawPost($sessionEndpoint, []);
            $sessionId = null;

            $jsonResponse = $raw['json'] ?? null;
            $code = (int) ($raw['status'] ?? 0);

            if ($jsonResponse !== null) {
                // v7.x/v8.x: Session ID is the JSON response body (plain string or array with value)
                if (is_string($jsonResponse)) {
                    $sessionId = $jsonResponse;
                } elseif (is_array($jsonResponse) && isset($jsonResponse['value'])) {
                    $sessionId = $jsonResponse['value'];
                }
            }

            if (!$sessionId) {
                 $errorDetails = $raw['body'] ?? 'No response body/details.';

                 if ($code === 401) {
                    throw new RuntimeException("Authentication failed: Invalid credentials or insufficient permissions (HTTP 401).");
                 }

                 throw new RuntimeException("Session endpoint {$sessionEndpoint} failed (HTTP {$code}): Missing session ID. Response details: " . substr((string)$errorDetails, 0, 100));
            }

            $this->sessionId = $sessionId;
            Log::debug('VCenterClient session created', [
                'device_id'  => $this->device->device_id,
                'session_id' => substr($sessionId, 0, 8) . '...',
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenterClient session creation failed completely', ['error' => $e->getMessage()]);
            throw new RuntimeException("VCenter session initialization failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Prepend the modern API root to the path.
     */
    protected function getFullApiPath(string $path): string
    {
        $path = ltrim($path, '/');
        return '/api/' . $path;
    }

    // -------- DeviceApiClientInterface required methods --------

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function supports(Device $device): bool
    {
        return in_array($device->os, ['vmware', 'vsphere', 'vmware-vcsa'], true)
            && ($device->apiConfig !== null || $this->apiConfig !== null);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function capabilities(): array
    {
        return ['sensors', 'ports', 'mempools', 'processors', 'inventory', 'ipv4', 'storage', 'ports_stats'];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            // Get hosts to collect sensor data
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return [];
            }

            foreach ($hosts as $host) {
                $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                $hostName = is_array($host) ? ($host['name'] ?? 'host') : 'host';

                if (!$hostId) {
                    continue;
                }

                try {
                    // Get host connection state and power state
                    $summary = $this->get("vcenter/host/{$hostId}");
                    $hostInfo = $summary['value'] ?? $summary;

                    // Connection state sensor
                    if (isset($hostInfo['connection_state'])) {
                        $stateMap = ['CONNECTED' => 2, 'DISCONNECTED' => 0, 'NOT_RESPONDING' => 1];
                        $state = strtoupper($hostInfo['connection_state']);
                        $stateValue = $stateMap[$state] ?? 3;

                        $sensors[] = [
                            'sensor_class' => 'state',
                            'sensor_type' => 'vmware',
                            'sensor_descr' => "$hostName Connection State",
                            'sensor_index' => "host-{$hostId}-connection",
                            'sensor_current' => $stateValue,
                            'sensor_limit' => null,
                            'sensor_limit_low' => null,
                            'states' => [
                                ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'disconnected'],
                                ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'not_responding'],
                                ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'connected'],
                                ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                            ],
                        ];
                    }

                    // Power state sensor
                    if (isset($hostInfo['power_state'])) {
                        $powerMap = ['POWERED_ON' => 2, 'POWERED_OFF' => 0, 'STANDBY' => 1];
                        $power = strtoupper($hostInfo['power_state']);
                        $powerValue = $powerMap[$power] ?? 3;

                        $sensors[] = [
                            'sensor_class' => 'state',
                            'sensor_type' => 'vmware',
                            'sensor_descr' => "$hostName Power State",
                            'sensor_index' => "host-{$hostId}-power",
                            'sensor_current' => $powerValue,
                            'sensor_limit' => null,
                            'sensor_limit_low' => null,
                            'states' => [
                                ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'powered_off'],
                                ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'standby'],
                                ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'powered_on'],
                                ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                            ],
                        ];
                    }

                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get host sensors', [
                        'host_id' => $hostId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Get VMs for VM state sensors
            $vmResponse = $this->get('vcenter/vm');
            $vms = $vmResponse['value'] ?? $vmResponse;

            if (is_array($vms)) {
                $vmCount = 0;
                $vmPoweredOn = 0;

                foreach ($vms as $vm) {
                    $vmCount++;
                    if (is_array($vm) && isset($vm['power_state']) && strtoupper($vm['power_state']) === 'POWERED_ON') {
                        $vmPoweredOn++;
                    }
                }

                // VM count sensor
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'vmware',
                    'sensor_descr' => 'Total VMs',
                    'sensor_index' => 'vm-total',
                    'sensor_current' => $vmCount,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];

                // Powered on VMs count
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'vmware',
                    'sensor_descr' => 'Powered On VMs',
                    'sensor_index' => 'vm-powered-on',
                    'sensor_current' => $vmPoweredOn,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchSensors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchPorts(Device $device): array
    {
        $ports = [];

        try {
            // Get hosts to collect network interface information
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return [];
            }

            $portIndex = 0;
            foreach ($hosts as $host) {
                $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                $hostName = is_array($host) ? ($host['name'] ?? 'host') : 'host';

                if (!$hostId) {
                    continue;
                }

                try {
                    // Get host network information
                    $network = $this->get("vcenter/host/{$hostId}/networking");
                    $nics = $network['value'] ?? [];

                    if (is_array($nics)) {
                        foreach ($nics as $nic) {
                            $nicKey = $nic['nic'] ?? "nic{$portIndex}";
                            $nicName = $nic['device'] ?? $nicKey;

                            // Map link status
                            $linkStatus = 'up';
                            $ifOperStatus = 'up';
                            if (isset($nic['link_status'])) {
                                $linkStatus = strtolower($nic['link_status']);
                                $ifOperStatus = ($linkStatus === 'up' || $linkStatus === 'connected') ? 'up' : 'down';
                            }

                            // Get speed (convert from Mbps to bps if available)
                            $speed = 0;
                            if (isset($nic['speed'])) {
                                $speed = $nic['speed'] * 1000000; // Convert Mbps to bps
                            }

                            $ports[] = [
                                'ifIndex' => $portIndex,
                                'ifName' => "{$hostName}:{$nicName}",
                                'ifDescr' => $nic['description'] ?? "{$hostName} {$nicName}",
                                'ifType' => 'ethernetCsmacd',
                                'ifOperStatus' => $ifOperStatus,
                                'ifAdminStatus' => $ifOperStatus,
                                'ifSpeed' => $speed,
                                'ifMtu' => $nic['mtu'] ?? 1500,
                                'ifPhysAddress' => $nic['mac_address'] ?? null,
                            ];

                            $portIndex++;
                        }
                    }

                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get host network', [
                        'host_id' => $hostId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        try {
            // Get hosts to collect memory information
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return [];
            }

            foreach ($hosts as $idx => $host) {
                $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                $hostName = is_array($host) ? ($host['name'] ?? "host-$idx") : "host-$idx";

                if (!$hostId) {
                    continue;
                }

                try {
                    // Get host summary for memory info
                    $summary = $this->get("vcenter/host/{$hostId}");
                    $hostInfo = $summary['value'] ?? $summary;

                    // Memory usage (vCenter provides in MB typically)
                    $memTotal = $hostInfo['memory_size_MiB'] ?? 0;

                    // Get detailed stats if available (would need performance API for actual usage)
                    // For now, we'll create the mempool structure with available info
                    if ($memTotal > 0) {
                        $mempools[] = [
                            'mempool_index' => "vcenter-host-{$idx}",
                            'mempool_type' => 'vmware-host',
                            'mempool_descr' => "{$hostName} Memory",
                            'mempool_total' => $memTotal * 1024 * 1024, // Convert MiB to bytes
                            'mempool_used' => 0, // Would need performance stats API
                            'mempool_free' => $memTotal * 1024 * 1024,
                            'mempool_perc' => 0,
                        ];
                    }

                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get host memory', [
                        'host_id' => $hostId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchMempools failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        try {
            // Get hosts to collect processor information
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return [];
            }

            foreach ($hosts as $idx => $host) {
                $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                $hostName = is_array($host) ? ($host['name'] ?? "host-$idx") : "host-$idx";

                if (!$hostId) {
                    continue;
                }

                try {
                    // Get host summary for CPU info
                    $summary = $this->get("vcenter/host/{$hostId}");
                    $hostInfo = $summary['value'] ?? $summary;

                    // Get number of CPU cores/sockets
                    $cpuCount = $hostInfo['cpu_count'] ?? 1;
                    $cpuModel = $hostInfo['cpu_model'] ?? 'VMware CPU';

                    // vCenter REST API doesn't provide real-time CPU usage in summary
                    // Would need Performance Manager API for actual usage
                    $processors[] = [
                        'processor_index' => "vcenter-host-{$idx}",
                        'processor_type' => 'vmware-host-cpu',
                        'processor_descr' => "{$hostName} - {$cpuModel} ({$cpuCount} cores)",
                        'processor_usage' => 0, // Would need performance stats API
                    ];

                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get host processor', [
                        'host_id' => $hostId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchProcessors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchInventory(Device $device): array
    {
        $inventory = [];
        $entPhysicalIndex = 1;

        try {
            // Get vCenter appliance info as the main chassis
            try {
                $appVersion = $this->get('appliance/system/version');
                $versionInfo = $appVersion['value'] ?? $appVersion;

                $inventory[] = [
                    'entPhysicalIndex' => $entPhysicalIndex++,
                    'entPhysicalDescr' => 'vCenter Server Appliance',
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => 'vCenter',
                    'entPhysicalModelName' => 'vCenter Server',
                    'entPhysicalSerialNum' => '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'VMware',
                    'entPhysicalParentRelPos' => -1,
                    'entPhysicalVendorType' => 'vmware-vcenter',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => $versionInfo['version'] ?? '',
                    'entPhysicalSoftwareRev' => $versionInfo['build'] ?? '',
                    'entPhysicalIsFRU' => 0,
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];
            } catch (\Exception $e) {
                Log::debug('VCenterClient failed to get appliance version', ['error' => $e->getMessage()]);
            }

            // Get hosts as modules
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (is_array($hosts)) {
                foreach ($hosts as $host) {
                    $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                    $hostName = is_array($host) ? ($host['name'] ?? 'host') : 'host';

                    if (!$hostId) {
                        continue;
                    }

                    try {
                        // Get detailed host information
                        $summary = $this->get("vcenter/host/{$hostId}");
                        $hostInfo = $summary['value'] ?? $summary;

                        $inventory[] = [
                            'entPhysicalIndex' => $entPhysicalIndex++,
                            'entPhysicalDescr' => "ESXi Host: {$hostName}",
                            'entPhysicalClass' => 'module',
                            'entPhysicalName' => $hostName,
                            'entPhysicalModelName' => $hostInfo['cpu_model'] ?? 'ESXi Host',
                            'entPhysicalSerialNum' => '',
                            'entPhysicalContainedIn' => 1,
                            'entPhysicalMfgName' => 'VMware',
                            'entPhysicalParentRelPos' => $entPhysicalIndex - 1,
                            'entPhysicalVendorType' => 'vmware-esxi',
                            'entPhysicalHardwareRev' => '',
                            'entPhysicalFirmwareRev' => $hostInfo['version'] ?? '',
                            'entPhysicalSoftwareRev' => '',
                            'entPhysicalIsFRU' => 0,
                            'entPhysicalAlias' => '',
                            'entPhysicalAssetID' => '',
                        ];

                    } catch (\Exception $e) {
                        Log::debug('VCenterClient failed to get host inventory', [
                            'host_id' => $hostId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Get VMs as components
            $vmResponse = $this->get('vcenter/vm');
            $vms = $vmResponse['value'] ?? $vmResponse;

            if (is_array($vms)) {
                foreach ($vms as $vm) {
                    $vmId = is_array($vm) ? ($vm['vm'] ?? null) : $vm;
                    $vmName = is_array($vm) ? ($vm['name'] ?? 'vm') : 'vm';
                    $powerState = is_array($vm) ? ($vm['power_state'] ?? 'UNKNOWN') : 'UNKNOWN';

                    if (!$vmId) {
                        continue;
                    }

                    $inventory[] = [
                        'entPhysicalIndex' => $entPhysicalIndex++,
                        'entPhysicalDescr' => "VM: {$vmName} [{$powerState}]",
                        'entPhysicalClass' => 'container',
                        'entPhysicalName' => $vmName,
                        'entPhysicalModelName' => 'Virtual Machine',
                        'entPhysicalSerialNum' => $vmId,
                        'entPhysicalContainedIn' => 1,
                        'entPhysicalMfgName' => 'VMware',
                        'entPhysicalParentRelPos' => $entPhysicalIndex - 1,
                        'entPhysicalVendorType' => 'vmware-vm',
                        'entPhysicalHardwareRev' => '',
                        'entPhysicalFirmwareRev' => '',
                        'entPhysicalSoftwareRev' => '',
                        'entPhysicalIsFRU' => 0,
                        'entPhysicalAlias' => '',
                        'entPhysicalAssetID' => '',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchInventory failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchStorage(Device $device): array
    {
        $storage = [];

        try {
            // Get datastores
            $response = $this->get('vcenter/datastore');
            $datastores = $response['value'] ?? $response;

            if (!is_array($datastores)) {
                return [];
            }

            foreach ($datastores as $idx => $ds) {
                // Get detailed datastore information
                $datastoreId = is_array($ds) ? ($ds['datastore'] ?? null) : $ds;
                if (!$datastoreId) {
                    continue;
                }

                try {
                    $details = $this->get("vcenter/datastore/{$datastoreId}");
                    $dsInfo = $details['value'] ?? $details;

                    $name = $dsInfo['name'] ?? "datastore-$idx";
                    $capacity = $dsInfo['capacity'] ?? 0;
                    $freeSpace = $dsInfo['free_space'] ?? 0;
                    $used = $capacity - $freeSpace;

                    $storage[] = [
                        'storage_index' => "ds-$idx",
                        'storage_descr' => $name,
                        'storage_type' => $dsInfo['type'] ?? 'vmware-datastore',
                        'storage_size' => $capacity,
                        'storage_used' => $used,
                        'storage_free' => $freeSpace,
                        'storage_units' => 1,
                        'storage_perc' => $capacity > 0 ? round(($used / $capacity) * 100, 2) : 0,
                    ];
                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get datastore details', [
                        'datastore_id' => $datastoreId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchStorage failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchTransceivers(Device $device): array
    {
        // vCenter is a management platform, transceivers not applicable
        return [];
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        $addresses = [];

        try {
            // Get VMs to extract their IP addresses
            $response = $this->get('vcenter/vm');
            $vms = $response['value'] ?? $response;

            if (!is_array($vms)) {
                return [];
            }

            foreach ($vms as $idx => $vm) {
                $vmId = is_array($vm) ? ($vm['vm'] ?? null) : $vm;
                if (!$vmId) {
                    continue;
                }

                try {
                    // Get guest network information
                    $guestNet = $this->get("vcenter/vm/{$vmId}/guest/networking/interfaces");
                    $interfaces = $guestNet['value'] ?? [];

                    foreach ($interfaces as $ifIdx => $iface) {
                        $ip = $iface['ip']['ip_addresses'] ?? [];

                        foreach ($ip as $ipInfo) {
                            $ipAddr = is_array($ipInfo) ? ($ipInfo['ip_address'] ?? null) : $ipInfo;
                            $prefixLen = is_array($ipInfo) ? ($ipInfo['prefix_length'] ?? 24) : 24;

                            if ($ipAddr && filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                $addresses[] = [
                                    'ifIndex' => ($idx * 100) + $ifIdx,
                                    'ipv4_address' => $ipAddr,
                                    'ipv4_prefixlen' => $prefixLen,
                                    'context_name' => 'vmware-' . ($vm['name'] ?? 'vm'),
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // VM might not have guest tools installed
                    Log::debug('VCenterClient failed to get VM network info', [
                        'vm_id' => $vmId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchIpv4Addresses failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchPortsStatistics(Device $device): array
    {
        $stats = [];

        try {
            // Get hosts to collect network statistics
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return [];
            }

            foreach ($hosts as $idx => $host) {
                $hostId = is_array($host) ? ($host['host'] ?? null) : $host;
                if (!$hostId) {
                    continue;
                }

                try {
                    // Get host network information
                    $network = $this->get("vcenter/host/{$hostId}/networking");
                    $interfaces = $network['value'] ?? [];

                    foreach ($interfaces as $ifIdx => $iface) {
                        $nicKey = $iface['nic'] ?? "nic$ifIdx";

                        // vCenter doesn't expose raw counters directly via REST API
                        // Would need to use Performance Manager API for detailed stats
                        $stats[] = [
                            'ifIndex' => ($idx * 100) + $ifIdx,
                            'ifInOctets' => 0,
                            'ifOutOctets' => 0,
                            'ifInErrors' => 0,
                            'ifOutErrors' => 0,
                            'ifInUcastPkts' => 0,
                            'ifOutUcastPkts' => 0,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to get host network', [
                        'host_id' => $hostId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchPortsStatistics failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function get(string $path, array $query = []): array
    {
        $fullPath = $this->getFullApiPath($path);
        return $this->httpClient->get($fullPath, $query);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function post(string $path, array $body = []): array
    {
        $fullPath = $this->getFullApiPath($path);
        return $this->httpClient->post($fullPath, $body);
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function isReachable(): bool
    {
        try {
            $raw = $this->httpClient->rawGet($this->apiRoot . '/session');
            return (int) ($raw['status'] ?? 0) < 500;
        } catch (\Throwable $e) {
            Log::debug('VCenterClient reachability check failed', ['error' => $e->getMessage()]);
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
                $resp = $this->get('appliance/system/version');
                if (is_array($resp)) {
                    $info['version'] = $resp['version'] ?? ($resp['value']['version'] ?? null);
                    $info['api_version'] = $resp['api_version'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::debug('VCenterClient getApiInfo version probe failed', ['error' => $e->getMessage()]);
            }

            return $info;
        } catch (\Throwable $e) {
            Log::error('VCenterClient getApiInfo failed', ['error' => $e->getMessage()]);
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