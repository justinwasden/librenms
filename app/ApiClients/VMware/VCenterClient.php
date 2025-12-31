<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;
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
    protected string $apiRoot = '/api'; // Hardcoded for v7.x and v8.x
    protected string $baseUrl;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Read config from device attributes (decrypt credentials as needed)
        $this->baseUrl = $device->getAttrib('api_base_url') ?? '';
        $username  = DeviceApiSettings::getCredential($device, 'api_credential_username');
        $password  = DeviceApiSettings::getCredential($device, 'api_credential_password');
        $verifyTls = (bool) $device->getAttrib('api_verify_ssl', true);
        $timeoutMs = (int) $device->getAttrib('api_credential_timeout_ms', 5000);

        // Mask password for logs
        $maskedPassword = $password !== null ? str_repeat('*', max(4, strlen($password))) : null;

        if (!$this->baseUrl) {
            throw new RuntimeException("API config for device {$device->device_id} is missing base_url");
        }

        // Strip /api suffix from base_url if present (client adds /api/ prefix itself)
        $this->baseUrl = rtrim($this->baseUrl, '/');
        if (str_ends_with($this->baseUrl, '/api')) {
            $this->baseUrl = substr($this->baseUrl, 0, -4);
        }

        // Initialize HTTP client using the DB's base_url for ALL requests
        // Get extra headers from device attributes
        $headers = [];
        $extraHeadersJson = $device->getAttrib('api_extra_headers');
        if ($extraHeadersJson) {
            $headers = json_decode($extraHeadersJson, true) ?? [];
        }
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
            'base_url'   => $this->baseUrl,
            'headers'    => $headers,
            'verify_tls' => $verifyTls,
            'timeout_ms' => $timeoutMs,
            'curl_opts'  => $curlOptions,
            // Disable circuit breaker - VMs without guest tools will cause expected
            // API failures that should not trip the circuit breaker
            'enable_circuit_breaker' => false,
        ], $device);

        Log::debug('VCenterClient init config', [
            'device_id'       => $this->device->device_id,
            'base_url'        => $this->baseUrl,
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
            // Log as debug since this is expected when probing non-vCenter devices
            Log::debug('VCenterClient session creation failed', ['error' => $e->getMessage()]);
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
            && $device->getAttrib('api_base_url');
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function capabilities(): array
    {
        return ['device_info', 'sensors', 'ports', 'mempools', 'processors', 'inventory', 'ipv4', 'storage', 'ports_stats', 'vlans', 'vminfo', 'clusters', 'hypervisor_hosts'];
    }

    /**
     * Fetch device information including hostname
     *
     * @param Device $device
     * @return array Device info (version, hostname, etc.)
     */
    public function fetchDeviceInfo(Device $device): array
    {
        $info = [];

        try {
            // Get vCenter appliance version
            $versionResp = $this->get('appliance/system/version');
            if (is_array($versionResp)) {
                $info['version'] = $versionResp['version'] ?? ($versionResp['value']['version'] ?? null);
                $info['build'] = $versionResp['build'] ?? ($versionResp['value']['build'] ?? null);
                $info['product'] = $versionResp['product'] ?? ($versionResp['value']['product'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::debug('VCenterClient fetchDeviceInfo version failed', ['error' => $e->getMessage()]);
        }

        try {
            // Get hostname from networking configuration
            $hostnameResp = $this->get('appliance/networking/dns/hostname');
            if (is_array($hostnameResp)) {
                $info['hostname'] = $hostnameResp['value'] ?? $hostnameResp['hostname'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::debug('VCenterClient fetchDeviceInfo hostname failed', ['error' => $e->getMessage()]);
        }

        return $info;
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
            // Add vCenter appliance aggregate port for overall traffic
            $ports[] = [
                'ifIndex' => 99999,
                'ifName' => 'vCenter Appliance',
                'ifDescr' => 'vCenter Appliance Aggregate Traffic',
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => 'up',
                'ifAdminStatus' => 'up',
                'ifSpeed' => 10000000000, // 10Gbps
                'ifMtu' => 1500,
                'ifPhysAddress' => '',
                'ifAlias' => 'Aggregate',
            ];

            // REMOVED: VM network adapter collection
            // VM network adapters should not be listed as infrastructure ports.
            // Only the vCenter Appliance aggregate port is shown for overall traffic monitoring.
            //
            // If ESXi host physical adapters (vmnic) are needed in the future, they should be
            // collected from /vcenter/host and /vcenter/network APIs instead of VM adapters.

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
            // Try to get vCenter appliance memory stats first
            // Note: This endpoint may not be available on all vCenter versions
            try {
                // Build query string with repeated item.names parameter manually
                // (vCenter API requires ?item.names=mem.util&item.names=mem.total format)
                $queryString = http_build_query([
                    'item.names' => 'mem.util',
                    'item.interval' => 'MINUTES5',
                    'item.function' => 'AVG',
                    'item.start_time' => date('c', strtotime('-5 minutes')),
                    'item.end_time' => date('c'),
                ]) . '&item.names=mem.total';

                $monitoringData = $this->httpClient->rawGet($this->getFullApiPath('appliance/monitoring/query') . '?' . $queryString)['json'] ?? [];

                $stats = $monitoringData['value'] ?? $monitoringData;
                $memUtil = null;
                $memTotal = null;

                if (is_array($stats)) {
                    foreach ($stats as $stat) {
                        $name = $stat['name'] ?? '';
                        $data = $stat['data'] ?? [];

                        if ($name === 'mem.util' && !empty($data)) {
                            $memUtil = end($data); // Get last value
                        } elseif ($name === 'mem.total' && !empty($data)) {
                            $memTotal = end($data); // Get last value in MB
                        }
                    }
                }

                if ($memTotal > 0) {
                    $memUsedMB = $memUtil > 0 ? ($memTotal * $memUtil / 100) : 0;
                    $memFreeMB = $memTotal - $memUsedMB;

                    $mempools[] = [
                        'mempool_index' => 'vcenter-appliance',
                        'mempool_type' => 'vmware-vcenter',
                        'mempool_descr' => 'vCenter Appliance Memory',
                        'mempool_total' => $memTotal * 1024 * 1024, // Convert MB to bytes
                        'mempool_used' => $memUsedMB * 1024 * 1024,
                        'mempool_free' => $memFreeMB * 1024 * 1024,
                        'mempool_perc' => $memUtil ?: 0,
                    ];
                }
            } catch (\Exception $e) {
                // Monitoring API may not be available or may have different format in older vCenter versions
                Log::debug('VCenterClient appliance monitoring not available (this is normal for some vCenter versions)', [
                    'device_id' => $device->device_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Get hosts to collect memory information
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return $mempools;
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

                    // Memory usage (vCenter provides in MiB)
                    $memTotal = $hostInfo['memory_size_MiB'] ?? 0;

                    // Get memory usage from hardware endpoint if available
                    $memUsed = 0;
                    $memPerc = 0;
                    try {
                        $hardware = $this->get("vcenter/host/{$hostId}/hardware");
                        $hwInfo = $hardware['value'] ?? $hardware;
                        $memoryInfo = $hwInfo['memory'] ?? [];

                        // Memory used in MiB
                        if (isset($memoryInfo['used_MiB'])) {
                            $memUsed = $memoryInfo['used_MiB'];
                        }
                    } catch (\Exception $perfEx) {
                        Log::debug('VCenterClient hardware endpoint not available for host memory', [
                            'host_id' => $hostId,
                            'error' => $perfEx->getMessage(),
                        ]);
                    }

                    if ($memTotal > 0) {
                        $memUsedBytes = $memUsed * 1024 * 1024;
                        $memTotalBytes = $memTotal * 1024 * 1024;
                        $memFreeBytes = $memTotalBytes - $memUsedBytes;
                        $memPerc = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0;

                        $mempools[] = [
                            'mempool_index' => "vcenter-host-{$idx}",
                            'mempool_type' => 'vmware-host',
                            'mempool_descr' => "{$hostName} Memory",
                            'mempool_total' => $memTotalBytes,
                            'mempool_used' => $memUsedBytes,
                            'mempool_free' => $memFreeBytes,
                            'mempool_perc' => $memPerc,
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
            // Try to get vCenter appliance CPU stats first
            // Note: This endpoint may not be available on all vCenter versions
            try {
                // Build query string manually for vCenter API format
                $queryString = http_build_query([
                    'item.names' => 'cpu.util',
                    'item.interval' => 'MINUTES5',
                    'item.function' => 'AVG',
                    'item.start_time' => date('c', strtotime('-5 minutes')),
                    'item.end_time' => date('c'),
                ]);

                $monitoringData = $this->httpClient->rawGet($this->getFullApiPath('appliance/monitoring/query') . '?' . $queryString)['json'] ?? [];

                $stats = $monitoringData['value'] ?? $monitoringData;
                $cpuUtil = null;

                if (is_array($stats)) {
                    foreach ($stats as $stat) {
                        $name = $stat['name'] ?? '';
                        $data = $stat['data'] ?? [];

                        if ($name === 'cpu.util' && !empty($data)) {
                            $cpuUtil = end($data); // Get last value
                            break;
                        }
                    }
                }

                if ($cpuUtil !== null) {
                    $processors[] = [
                        'processor_index' => 'vcenter-appliance',
                        'processor_type' => 'vmware-vcenter-cpu',
                        'processor_descr' => 'vCenter Appliance CPU',
                        'processor_usage' => $cpuUtil,
                    ];
                }
            } catch (\Exception $e) {
                // Monitoring API may not be available or may have different format in older vCenter versions
                Log::debug('VCenterClient appliance monitoring not available (this is normal for some vCenter versions)', [
                    'device_id' => $device->device_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Get hosts to collect processor information
            $response = $this->get('vcenter/host');
            $hosts = $response['value'] ?? $response;

            if (!is_array($hosts)) {
                return $processors;
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

                    // Get CPU usage from hardware endpoint if available
                    $cpuUsage = 0;
                    try {
                        $hardware = $this->get("vcenter/host/{$hostId}/hardware");
                        $hwInfo = $hardware['value'] ?? $hardware;
                        $cpuInfo = $hwInfo['cpu'] ?? [];

                        // CPU usage percentage
                        if (isset($cpuInfo['usage_percent'])) {
                            $cpuUsage = round($cpuInfo['usage_percent'], 2);
                        } elseif (isset($cpuInfo['used_Hz']) && isset($cpuInfo['capacity_Hz']) && $cpuInfo['capacity_Hz'] > 0) {
                            $cpuUsage = round(($cpuInfo['used_Hz'] / $cpuInfo['capacity_Hz']) * 100, 2);
                        }
                    } catch (\Exception $perfEx) {
                        Log::debug('VCenterClient hardware endpoint not available for host CPU', [
                            'host_id' => $hostId,
                            'error' => $perfEx->getMessage(),
                        ]);
                    }

                    $processors[] = [
                        'processor_index' => "vcenter-host-{$idx}",
                        'processor_type' => 'vmware-host-cpu',
                        'processor_descr' => "{$hostName} - {$cpuModel} ({$cpuCount} cores)",
                        'processor_usage' => $cpuUsage,
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
            $chassisIndex = $entPhysicalIndex++;
            try {
                $appVersion = $this->get('appliance/system/version');
                $versionInfo = $appVersion['value'] ?? $appVersion;

                $inventory[] = [
                    'entPhysicalIndex' => $chassisIndex,
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

            // Get clusters first (if available)
            $clusters = [];
            $clusterIndexMap = [];
            try {
                $clusterResponse = $this->get('vcenter/cluster');
                $clusterList = $clusterResponse['value'] ?? $clusterResponse;

                if (is_array($clusterList)) {
                    foreach ($clusterList as $cluster) {
                        $clusterId = is_array($cluster) ? ($cluster['cluster'] ?? null) : $cluster;
                        $clusterName = is_array($cluster) ? ($cluster['name'] ?? 'cluster') : 'cluster';

                        if ($clusterId) {
                            $clusterPhysicalIndex = $entPhysicalIndex++;
                            $clusterIndexMap[$clusterId] = $clusterPhysicalIndex;

                            $inventory[] = [
                                'entPhysicalIndex' => $clusterPhysicalIndex,
                                'entPhysicalDescr' => "Cluster: {$clusterName}",
                                'entPhysicalClass' => 'module',
                                'entPhysicalName' => $clusterName,
                                'entPhysicalModelName' => 'vSphere Cluster',
                                'entPhysicalSerialNum' => $clusterId,
                                'entPhysicalContainedIn' => $chassisIndex,
                                'entPhysicalMfgName' => 'VMware',
                                'entPhysicalParentRelPos' => -1,
                                'entPhysicalVendorType' => 'vmware-cluster',
                                'entPhysicalHardwareRev' => '',
                                'entPhysicalFirmwareRev' => '',
                                'entPhysicalSoftwareRev' => '',
                                'entPhysicalIsFRU' => 0,
                                'entPhysicalAlias' => '',
                                'entPhysicalAssetID' => '',
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug('VCenterClient failed to get clusters', ['error' => $e->getMessage()]);
            }

            // Get hosts and map them to their clusters
            $hostIndexMap = [];
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

                        // Determine parent (cluster or chassis)
                        $clusterId = $host['cluster'] ?? null;
                        $parentIndex = $clusterId && isset($clusterIndexMap[$clusterId])
                            ? $clusterIndexMap[$clusterId]
                            : $chassisIndex;

                        $hostPhysicalIndex = $entPhysicalIndex++;
                        $hostIndexMap[$hostId] = $hostPhysicalIndex;

                        $inventory[] = [
                            'entPhysicalIndex' => $hostPhysicalIndex,
                            'entPhysicalDescr' => "ESXi Host: {$hostName}",
                            'entPhysicalClass' => 'module',
                            'entPhysicalName' => $hostName,
                            'entPhysicalModelName' => $hostInfo['cpu_model'] ?? 'ESXi Host',
                            'entPhysicalSerialNum' => '',
                            'entPhysicalContainedIn' => $parentIndex,
                            'entPhysicalMfgName' => 'VMware',
                            'entPhysicalParentRelPos' => -1,
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

            // Get VMs and place them under their host
            $vmResponse = $this->get('vcenter/vm');
            $vms = $vmResponse['value'] ?? $vmResponse;

            if (is_array($vms)) {
                foreach ($vms as $vm) {
                    $vmId = is_array($vm) ? ($vm['vm'] ?? null) : $vm;
                    $vmName = is_array($vm) ? ($vm['name'] ?? 'vm') : 'vm';
                    $powerState = is_array($vm) ? ($vm['power_state'] ?? 'UNKNOWN') : 'UNKNOWN';
                    $vmHost = is_array($vm) ? ($vm['host'] ?? null) : null;

                    if (!$vmId) {
                        continue;
                    }

                    // Determine parent (host if known, otherwise chassis)
                    $parentIndex = $vmHost && isset($hostIndexMap[$vmHost])
                        ? $hostIndexMap[$vmHost]
                        : $chassisIndex;

                    $inventory[] = [
                        'entPhysicalIndex' => $entPhysicalIndex++,
                        'entPhysicalDescr' => "VM: {$vmName} [{$powerState}]",
                        'entPhysicalClass' => 'container',
                        'entPhysicalName' => $vmName,
                        'entPhysicalModelName' => 'Virtual Machine',
                        'entPhysicalSerialNum' => $vmId,
                        'entPhysicalContainedIn' => $parentIndex,
                        'entPhysicalMfgName' => 'VMware',
                        'entPhysicalParentRelPos' => -1,
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
                // Use data from list endpoint which includes capacity
                // (detailed endpoint only returns free_space without capacity)
                if (!is_array($ds)) {
                    continue;
                }

                try {
                    $name = $ds['name'] ?? "datastore-$idx";
                    $capacity = $ds['capacity'] ?? 0;
                    $freeSpace = $ds['free_space'] ?? 0;
                    $used = $capacity - $freeSpace;

                    $storage[] = [
                        'storage_index' => "ds-$idx",
                        'storage_descr' => $name,
                        'storage_type' => $ds['type'] ?? 'vmware-datastore',
                        'storage_size' => $capacity,
                        'storage_used' => $used,
                        'storage_free' => $freeSpace,
                        'storage_units' => 1,
                        'storage_perc' => $capacity > 0 ? round(($used / $capacity) * 100, 2) : 0,
                    ];
                } catch (\Exception $e) {
                    Log::debug('VCenterClient failed to process datastore', [
                        'datastore' => $ds,
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

            foreach ($vms as $vm) {
                $vmId = $vm['vm'] ?? null;
                $vmName = $vm['name'] ?? 'unknown';

                if (!$vmId) {
                    continue;
                }

                try {
                    // Get guest network information
                    $guestNet = $this->get("vcenter/vm/{$vmId}/guest/networking/interfaces");
                    $interfaces = $guestNet['value'] ?? [];

                    foreach ($interfaces as $iface) {
                        $macAddress = $iface['mac_address'] ?? '';
                        $nicId = $iface['nic'] ?? '';
                        $ipData = $iface['ip'] ?? [];
                        $ipAddresses = $ipData['ip_addresses'] ?? [];

                        foreach ($ipAddresses as $ipInfo) {
                            $ipAddr = $ipInfo['ip_address'] ?? null;
                            $prefixLen = $ipInfo['prefix_length'] ?? 24;

                            // Skip IPv6 and invalid IPs
                            if (!$ipAddr || !filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                continue;
                            }

                            // Use MAC address to help match with port
                            $context = $macAddress ?: $nicId;
                            $addresses[] = [
                                'ipv4_address' => $ipAddr,
                                'ipv4_prefixlen' => $prefixLen,
                                'context_name' => $macAddress, // Use MAC to match with port
                                'port_id' => null, // Will be matched by MAC
                            ];
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
     * Fetch VLANs from vCenter port groups
     */
    public function fetchVlans(Device $device): array
    {
        $vlans = [];

        try {
            // Get port groups (networks)
            $response = $this->get('vcenter/network');
            $portGroups = $response['value'] ?? $response;

            if (!is_array($portGroups)) {
                return [];
            }

            foreach ($portGroups as $pg) {
                $name = $pg['name'] ?? '';
                $type = $pg['type'] ?? '';
                $networkId = $pg['network'] ?? '';

                if (!$name) {
                    continue;
                }

                // Try to extract VLAN ID from name (format: "123-Name" or "Name")
                $vlanId = null;
                if (preg_match('/^(\d+)-/', $name, $matches)) {
                    $vlanId = (int)$matches[1];
                }

                // If no VLAN ID found, use a hash of the name as ID
                if ($vlanId === null) {
                    $vlanId = (crc32($name) & 0x7FFFFFFF) % 4096; // Keep within valid VLAN range
                }

                $vlans[] = [
                    'vlan_vlan' => $vlanId,
                    'vlan_domain' => 1,
                    'vlan_name' => $name,
                    'vlan_type' => $type === 'DISTRIBUTED_PORTGROUP' ? 'ethernet' : 'ethernet',
                    'vlan_mtu' => null,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchVlans failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vlans;
    }

    /**
     * Implement abstract method from DeviceApiClientInterface
     */
    public function fetchPortsStatistics(Device $device): array
    {
        $stats = [];

        try {
            // Try to get vCenter appliance network stats
            // Note: This endpoint may not be available on all vCenter versions
            try {
                // Build query string with repeated parameters manually
                $queryString = http_build_query([
                    'item.names' => 'net.rx.rate',
                    'item.interval' => 'MINUTES5',
                    'item.function' => 'AVG',
                    'item.start_time' => date('c', strtotime('-5 minutes')),
                    'item.end_time' => date('c'),
                ]) . '&item.names=net.tx.rate';

                $monitoringData = $this->httpClient->rawGet($this->getFullApiPath('appliance/monitoring/query') . '?' . $queryString)['json'] ?? [];

                $monStats = $monitoringData['value'] ?? $monitoringData;
                $rxRate = null;
                $txRate = null;

                if (is_array($monStats)) {
                    foreach ($monStats as $stat) {
                        $name = $stat['name'] ?? '';
                        $data = $stat['data'] ?? [];

                        if ($name === 'net.rx.rate' && !empty($data)) {
                            $rxRate = end($data); // KB/s
                        } elseif ($name === 'net.tx.rate' && !empty($data)) {
                            $txRate = end($data); // KB/s
                        }
                    }
                }

                if ($rxRate !== null || $txRate !== null) {
                    $stats[] = [
                        'ifIndex' => 99999, // Matches the aggregate port created in fetchPorts
                        'ifInOctets_rate' => ($rxRate ?? 0) * 1024, // Convert KB/s to B/s
                        'ifOutOctets_rate' => ($txRate ?? 0) * 1024,
                        'ifInBits_rate' => ($rxRate ?? 0) * 1024 * 8,
                        'ifOutBits_rate' => ($txRate ?? 0) * 1024 * 8,
                    ];
                }
            } catch (\Exception $e) {
                // Monitoring API may not be available or may have different format in older vCenter versions
                Log::debug('VCenterClient appliance monitoring not available (this is normal for some vCenter versions)', [
                    'device_id' => $device->device_id,
                    'error' => $e->getMessage(),
                ]);
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
                'base_url'    => $this->baseUrl,
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
                'base_url'    => $this->baseUrl,
                'api_version' => null,
                'version'     => null,
                'error'       => $e->getMessage(),
            ];
        }
    }

    public function fetchVms(Device $device): array
    {
        $vms = [];

        try {
            // Get all VMs from vCenter
            $vmResponse = $this->get('vcenter/vm');
            $vmList = $vmResponse['value'] ?? $vmResponse;

            if (!is_array($vmList)) {
                Log::warning('VCenterClient fetchVms: Invalid response format');
                return [];
            }

            foreach ($vmList as $vm) {
                if (!is_array($vm)) {
                    continue;
                }

                $vmId = $vm['vm'] ?? null;
                if (!$vmId) {
                    continue;
                }

                // Map vCenter power states to LibreNMS PowerState integer values
                // PowerState: OFF = 0, ON = 1, SUSPENDED = 2, UNKNOWN = 3
                $powerState = $vm['power_state'] ?? 'UNKNOWN';
                $vmState = match(strtoupper($powerState)) {
                    'POWERED_ON' => 1,  // PowerState::ON
                    'POWERED_OFF' => 0, // PowerState::OFF
                    'SUSPENDED' => 2,   // PowerState::SUSPENDED
                    default => 3,       // PowerState::UNKNOWN
                };

                // Try to get more detailed VM information
                $vmDetails = null;
                try {
                    $vmDetailsResponse = $this->get("vcenter/vm/{$vmId}");
                    $vmDetails = $vmDetailsResponse['value'] ?? $vmDetailsResponse;
                } catch (\Exception $e) {
                    Log::debug("VCenterClient: Could not fetch details for VM {$vmId}: {$e->getMessage()}");
                }

                // Extract guest OS information
                $guestOS = 'Other';
                if ($vmDetails && isset($vmDetails['guest_OS'])) {
                    $guestOS = $vmDetails['guest_OS'];
                } elseif (isset($vm['guest_OS'])) {
                    $guestOS = $vm['guest_OS'];
                }

                // Extract memory size (in MB)
                $memSize = null;
                if ($vmDetails && isset($vmDetails['memory'])) {
                    $memSize = isset($vmDetails['memory']['size_MiB']) ? (int) $vmDetails['memory']['size_MiB'] : null;
                } elseif (isset($vm['memory_size_MiB'])) {
                    $memSize = (int) $vm['memory_size_MiB'];
                }

                // Extract CPU count
                $cpuCount = null;
                if ($vmDetails && isset($vmDetails['cpu'])) {
                    $cpuCount = isset($vmDetails['cpu']['count']) ? (int) $vmDetails['cpu']['count'] : null;
                } elseif (isset($vm['cpu_count'])) {
                    $cpuCount = (int) $vm['cpu_count'];
                }

                // Extract numeric ID from vCenter VM identifier (e.g., "vm-101531" -> 101531)
                $numericId = $vmId;
                if (preg_match('/^vm-(\d+)$/', $vmId, $matches)) {
                    $numericId = $matches[1];
                }

                $vms[] = [
                    'vm_type' => 'vmware',
                    'vmwVmVMID' => (int) $numericId,
                    'vmwVmDisplayName' => $vm['name'] ?? "VM-{$vmId}",
                    'vmwVmGuestOS' => $guestOS,
                    'vmwVmMemSize' => $memSize,
                    'vmwVmCpus' => $cpuCount,
                    'vmwVmState' => $vmState,
                    'vmwVmHostId' => $vm['host'] ?? null, // Capture the ESXi host where VM is running
                ];
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchVms failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vms;
    }

    /**
     * Fetch datacenters and clusters
     *
     * @param Device $device
     * @return array
     */
    public function fetchClusters(Device $device): array
    {
        $clusters = [];

        try {
            // Fetch datacenters
            $dcResponse = $this->get('vcenter/datacenter');
            $datacenters = $dcResponse['value'] ?? $dcResponse;

            if (!is_array($datacenters)) {
                Log::warning('VCenterClient fetchClusters: Invalid datacenter response format');
                return [];
            }

            // Add datacenters as top-level clusters
            foreach ($datacenters as $dc) {
                if (!is_array($dc) || !isset($dc['datacenter'], $dc['name'])) {
                    continue;
                }

                $clusters[] = [
                    'cluster_type' => 'vmware',
                    'cluster_id' => $dc['datacenter'],
                    'cluster_name' => $dc['name'],
                    'parent_id' => null,
                    'parent_name' => null,
                    'cluster_level' => 'datacenter',
                    'metadata' => [],
                ];
            }

            // Fetch clusters within datacenters
            $clusterResponse = $this->get('vcenter/cluster');
            $vcenterclusters = $clusterResponse['value'] ?? $clusterResponse;

            if (is_array($vcenterclusters)) {
                foreach ($vcenterclusters as $cluster) {
                    if (!is_array($cluster) || !isset($cluster['cluster'], $cluster['name'])) {
                        continue;
                    }

                    // Try to get more details about the cluster
                    $clusterDetails = null;
                    try {
                        $detailsResponse = $this->get("vcenter/cluster/{$cluster['cluster']}");
                        $clusterDetails = $detailsResponse['value'] ?? $detailsResponse;
                    } catch (\Exception $e) {
                        Log::debug("VCenterClient: Could not fetch cluster details for {$cluster['cluster']}: {$e->getMessage()}");
                    }

                    $metadata = [];
                    if ($clusterDetails) {
                        $metadata['drs_enabled'] = $clusterDetails['drs_enabled'] ?? null;
                        $metadata['ha_enabled'] = $clusterDetails['ha_enabled'] ?? null;
                    }

                    $clusters[] = [
                        'cluster_type' => 'vmware',
                        'cluster_id' => $cluster['cluster'],
                        'cluster_name' => $cluster['name'],
                        'parent_id' => null, // vCenter API doesn't directly expose DC-cluster relationship in cluster list
                        'parent_name' => null,
                        'cluster_level' => 'cluster',
                        'metadata' => $metadata,
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchClusters failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $clusters;
    }

    /**
     * Fetch ESXi hosts managed by this vCenter
     *
     * @param Device $device
     * @return array
     */
    public function fetchHosts(Device $device): array
    {
        $hosts = [];

        try {
            $hostResponse = $this->get('vcenter/host');
            $hostList = $hostResponse['value'] ?? $hostResponse;

            if (!is_array($hostList)) {
                Log::warning('VCenterClient fetchHosts: Invalid response format');
                return [];
            }

            foreach ($hostList as $host) {
                if (!is_array($host) || !isset($host['host'], $host['name'])) {
                    continue;
                }

                // Try to get detailed host information
                $hostDetails = null;
                try {
                    $detailsResponse = $this->get("vcenter/host/{$host['host']}");
                    $hostDetails = $detailsResponse['value'] ?? $detailsResponse;
                } catch (\Exception $e) {
                    Log::debug("VCenterClient: Could not fetch host details for {$host['host']}: {$e->getMessage()}");
                }

                // Map vCenter connection states to our status values
                $connectionState = $host['connection_state'] ?? ($hostDetails['connection_state'] ?? 'UNKNOWN');
                $status = match(strtoupper($connectionState)) {
                    'CONNECTED' => 'connected',
                    'DISCONNECTED' => 'disconnected',
                    'NOT_RESPONDING' => 'not_responding',
                    default => strtolower($connectionState),
                };

                // Map power state
                $powerState = $host['power_state'] ?? ($hostDetails['power_state'] ?? null);
                if ($powerState === 'POWERED_OFF' || $powerState === 'STANDBY') {
                    $status = 'standby';
                }

                $cpuCores = null;
                $cpuThreads = null;
                $memoryTotal = null;
                $version = null;

                if ($hostDetails) {
                    if (isset($hostDetails['hardware']['cpu']['cores'])) {
                        $cpuCores = $hostDetails['hardware']['cpu']['cores'];
                    }
                    if (isset($hostDetails['hardware']['cpu']['threads'])) {
                        $cpuThreads = $hostDetails['hardware']['cpu']['threads'];
                    }
                    if (isset($hostDetails['hardware']['memory']['size_MiB'])) {
                        $memoryTotal = $hostDetails['hardware']['memory']['size_MiB'] * 1048576; // Convert to bytes
                    }
                    if (isset($hostDetails['version'])) {
                        $version = $hostDetails['version'];
                    }
                }

                $hosts[] = [
                    'host_type' => 'esxi',
                    'host_id' => $host['host'],
                    'host_name' => $host['name'],
                    'cluster_id' => null, // Will be populated if we can determine cluster membership
                    'role' => 'node', // ESXi hosts are typically cluster nodes
                    'status' => $status,
                    'version' => $version,
                    'cpu_cores' => $cpuCores,
                    'cpu_threads' => $cpuThreads,
                    'memory_total' => $memoryTotal,
                    'ip_address' => null, // vCenter doesn't directly expose management IP in list
                    'metadata' => [
                        'connection_state' => $connectionState,
                        'power_state' => $powerState,
                    ],
                ];
            }

        } catch (\Exception $e) {
            Log::warning('VCenterClient fetchHosts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $hosts;
    }
}