<?php

namespace App\ApiClients\Cisco;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;

/**
 * Cisco Firepower Threat Defense (FTD) API Client
 *
 * Implements OAuth 2.0 authentication with JWT tokens
 * Documentation: https://developer.cisco.com/docs/ftd-api-reference/
 *
 * API Endpoints:
 * - /api/fdm/v6/fdm/token - Authentication
 * - /api/fdm/v6/operational/systeminfo - System information
 * - /api/fdm/v6/object/physicalinterfaces - Physical interfaces
 * - /api/fdm/v6/object/virtualinterfaces - Virtual interfaces
 * - /api/fdm/v6/operational/metrics - System metrics
 * - /api/fdm/v6/operational/cpustats - CPU statistics
 * - /api/fdm/v6/operational/memorystats - Memory statistics
 * - /api/fdm/v6/operational/diskstats - Disk statistics
 * - /api/fdm/v6/operational/interfacestats - Interface statistics
 */
class FtdApiClient implements DeviceApiClientInterface
{
    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected string $baseUrl;
    protected ?string $accessToken = null;
    protected ?string $refreshToken = null;
    protected ?int $tokenExpiry = null;
    protected ?int $refreshTokenExpiry = null;
    protected string $tokenEndpoint = '/api/fdm/v6/fdm/token';
    protected string $apiVersion = 'v6';

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Read from device attributes first, then fall back to tables
        $this->baseUrl = $device->getAttrib('api_base_url') ?? '';

        // Fall back to device_api_configs table if no attribute
        if (empty($this->baseUrl) && $device->apiConfig) {
            $this->baseUrl = $device->apiConfig->base_url ?? '';
        }

        // Initialize HTTP client
        $verifySsl = $device->getAttrib('api_verify_ssl');
        $this->httpClient = new DeviceHttpClient([
            'base_url' => $this->baseUrl,
            'verify_tls' => $verifySsl !== null ? (bool) $verifySsl : true,
            'timeout_ms' => 30000,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ], $device);

        // Override token endpoint if specified in config
        $customEndpoint = $device->getAttrib('api_credential_token_endpoint');
        if ($customEndpoint) {
            $this->tokenEndpoint = $customEndpoint;
        }

        // Custom API version
        $version = $device->getAttrib('api_credential_api_version');
        if ($version) {
            $this->apiVersion = $version;
        }
    }

    /**
     * Check if device is supported
     */
    public function supports(Device $device): bool
    {
        $templateKey = $device->getAttrib('api_template_key');

        return $templateKey === 'cisco_ftd';
    }

    /**
     * Get supported capabilities
     */
    public function capabilities(): array
    {
        return [
            'sensors',
            'ports',
            'processors',
            'mempools',
            'inventory',
            'storage',
            'ports_stats',
        ];
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
        // Read credentials from device attributes (decrypt if encrypted)
        $username = DeviceApiSettings::getCredential($this->device, 'api_credential_username');
        $password = DeviceApiSettings::getCredential($this->device, 'api_credential_password');

        if (! $username || ! $password) {
            Log::error('FTD API: Missing credentials', ['device_id' => $this->device->device_id]);

            return false;
        }

        try {
            $response = $this->httpClient->post($this->tokenEndpoint, [
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
            ]);

            if (isset($response['access_token'])) {
                $this->accessToken = $response['access_token'];
                $this->refreshToken = $response['refresh_token'] ?? null;
                $this->tokenExpiry = time() + ($response['expires_in'] ?? 1800) - 60; // 60s buffer
                $this->refreshTokenExpiry = time() + ($response['refresh_expires_in'] ?? 2400) - 60;

                Log::debug('FTD API: Authentication successful', [
                    'device_id' => $this->device->device_id,
                    'expires_in' => $response['expires_in'] ?? null,
                ]);

                return true;
            }

            Log::error('FTD API: No access token in response', [
                'device_id' => $this->device->device_id,
            ]);

            return false;
        } catch (\Throwable $e) {
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
        if (! $this->refreshToken) {
            return false;
        }

        try {
            $response = $this->httpClient->post($this->tokenEndpoint, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
            ]);

            if (isset($response['access_token'])) {
                $this->accessToken = $response['access_token'];
                $this->refreshToken = $response['refresh_token'] ?? $this->refreshToken;
                $this->tokenExpiry = time() + ($response['expires_in'] ?? 1800) - 60;
                $this->refreshTokenExpiry = time() + ($response['refresh_expires_in'] ?? 2400) - 60;

                Log::debug('FTD API: Token refreshed successfully', [
                    'device_id' => $this->device->device_id,
                ]);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::debug('FTD API: Token refresh failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Make authenticated GET request
     */
    public function get(string $path, array $query = []): array
    {
        if (! $this->authenticate()) {
            return [];
        }

        try {
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
     * Make authenticated POST request
     */
    public function post(string $path, array $body = []): array
    {
        if (! $this->authenticate()) {
            return [];
        }

        try {
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
     * Build API path with version
     */
    protected function apiPath(string $endpoint): string
    {
        return "/api/fdm/{$this->apiVersion}/" . ltrim($endpoint, '/');
    }

    // =========================================================================
    // DeviceApiClientInterface Implementation - Fetch Methods
    // =========================================================================

    /**
     * Fetch sensors (CPU, memory, disk, connections, throughput)
     */
    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        // Fetch CPU stats
        $cpuStats = $this->get($this->apiPath('operational/cpustats'));
        if (! empty($cpuStats)) {
            $sensors = array_merge($sensors, $this->normalizeCpuSensors($cpuStats));
        }

        // Fetch memory stats
        $memStats = $this->get($this->apiPath('operational/memorystats'));
        if (! empty($memStats)) {
            $sensors = array_merge($sensors, $this->normalizeMemorySensors($memStats));
        }

        // Fetch disk stats
        $diskStats = $this->get($this->apiPath('operational/diskstats'));
        if (! empty($diskStats)) {
            $sensors = array_merge($sensors, $this->normalizeDiskSensors($diskStats));
        }

        // Fetch system metrics (connections, throughput, etc.)
        $metrics = $this->get($this->apiPath('operational/metrics'));
        if (! empty($metrics)) {
            $sensors = array_merge($sensors, $this->normalizeMetricSensors($metrics));
        }

        // Fetch HA status if available
        $haStatus = $this->get($this->apiPath('operational/hastatus'));
        if (! empty($haStatus)) {
            $sensors = array_merge($sensors, $this->normalizeHaSensors($haStatus));
        }

        Log::debug('FTD: Fetched sensors', [
            'device_id' => $this->device->device_id,
            'count' => count($sensors),
        ]);

        return $sensors;
    }

    /**
     * Fetch network interfaces/ports
     */
    public function fetchPorts(Device $device): array
    {
        $ports = [];
        $ifIndex = 1;

        // Fetch physical interfaces
        $physicalInterfaces = $this->get($this->apiPath('object/physicalinterfaces'));
        foreach ($physicalInterfaces['items'] ?? [] as $iface) {
            $ports[] = $this->normalizeInterface($iface, $ifIndex++);
        }

        // Fetch sub-interfaces
        $subInterfaces = $this->get($this->apiPath('object/subinterfaces'));
        foreach ($subInterfaces['items'] ?? [] as $iface) {
            $ports[] = $this->normalizeInterface($iface, $ifIndex++, 'subinterface');
        }

        // Fetch EtherChannels/port channels
        $etherChannels = $this->get($this->apiPath('object/etherchannels'));
        foreach ($etherChannels['items'] ?? [] as $iface) {
            $ports[] = $this->normalizeInterface($iface, $ifIndex++, 'etherchannel');
        }

        // Fetch bridge groups
        $bridgeGroups = $this->get($this->apiPath('object/bridgegroupinterfaces'));
        foreach ($bridgeGroups['items'] ?? [] as $iface) {
            $ports[] = $this->normalizeInterface($iface, $ifIndex++, 'bridgegroup');
        }

        Log::debug('FTD: Fetched ports', [
            'device_id' => $this->device->device_id,
            'count' => count($ports),
        ]);

        return $ports;
    }

    /**
     * Fetch CPU/processor information
     */
    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        // Get CPU stats
        $cpuStats = $this->get($this->apiPath('operational/cpustats'));

        if (! empty($cpuStats['items'])) {
            foreach ($cpuStats['items'] as $idx => $cpu) {
                $processors[] = [
                    'processor_index' => $idx,
                    'processor_type' => 'ftd-cpu',
                    'processor_descr' => $cpu['name'] ?? "CPU {$idx}",
                    'processor_usage' => $cpu['used'] ?? $cpu['usage'] ?? $cpu['cpuUsage'] ?? null,
                ];
            }
        } elseif (isset($cpuStats['cpu5minAvg']) || isset($cpuStats['cpuUsage'])) {
            // Single CPU reported
            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'ftd-cpu',
                'processor_descr' => 'System CPU',
                'processor_usage' => $cpuStats['cpu5minAvg'] ?? $cpuStats['cpuUsage'] ?? null,
            ];
        }

        // Also check system info for CPU count
        $sysInfo = $this->get($this->apiPath('operational/systeminfo'));
        if (! empty($sysInfo) && empty($processors)) {
            $cpuCount = $sysInfo['cpuCores'] ?? $sysInfo['numCpus'] ?? 1;
            for ($i = 0; $i < $cpuCount; $i++) {
                $processors[] = [
                    'processor_index' => $i,
                    'processor_type' => 'ftd-cpu',
                    'processor_descr' => "CPU {$i}",
                    'processor_usage' => null, // Will be polled later
                ];
            }
        }

        Log::debug('FTD: Fetched processors', [
            'device_id' => $this->device->device_id,
            'count' => count($processors),
        ]);

        return $processors;
    }

    /**
     * Fetch memory pools
     */
    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        // Get memory stats
        $memStats = $this->get($this->apiPath('operational/memorystats'));

        if (! empty($memStats)) {
            // System memory
            if (isset($memStats['memoryTotal']) || isset($memStats['total'])) {
                $total = $memStats['memoryTotal'] ?? $memStats['total'] ?? 0;
                $used = $memStats['memoryUsed'] ?? $memStats['used'] ?? 0;
                $free = $memStats['memoryFree'] ?? $memStats['free'] ?? ($total - $used);

                $mempools[] = [
                    'mempool_index' => 0,
                    'mempool_type' => 'ftd',
                    'mempool_descr' => 'System Memory',
                    'mempool_total' => $total,
                    'mempool_used' => $used,
                    'mempool_free' => $free,
                    'mempool_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                ];
            }

            // Per-process memory if available
            if (! empty($memStats['items'])) {
                foreach ($memStats['items'] as $idx => $mem) {
                    $total = $mem['total'] ?? $mem['memoryTotal'] ?? 0;
                    $used = $mem['used'] ?? $mem['memoryUsed'] ?? 0;

                    $mempools[] = [
                        'mempool_index' => $idx + 1,
                        'mempool_type' => 'ftd',
                        'mempool_descr' => $mem['name'] ?? $mem['process'] ?? "Memory Pool {$idx}",
                        'mempool_total' => $total,
                        'mempool_used' => $used,
                        'mempool_free' => $total - $used,
                        'mempool_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                    ];
                }
            }
        }

        Log::debug('FTD: Fetched mempools', [
            'device_id' => $this->device->device_id,
            'count' => count($mempools),
        ]);

        return $mempools;
    }

    /**
     * Fetch device inventory
     */
    public function fetchInventory(Device $device): array
    {
        $inventory = [];

        // Get system info
        $sysInfo = $this->get($this->apiPath('operational/systeminfo'));

        if (! empty($sysInfo)) {
            // Main device/chassis
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Cisco FTD ' . ($sysInfo['model'] ?? 'Firewall'),
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $sysInfo['hostname'] ?? $sysInfo['name'] ?? 'FTD',
                'entPhysicalModelName' => $sysInfo['model'] ?? '',
                'entPhysicalSerialNum' => $sysInfo['serialNumber'] ?? $sysInfo['serial'] ?? '',
                'entPhysicalSoftwareRev' => $sysInfo['softwareVersion'] ?? $sysInfo['version'] ?? '',
                'entPhysicalFirmwareRev' => $sysInfo['firmwareVersion'] ?? '',
                'entPhysicalHardwareRev' => $sysInfo['hardwareVersion'] ?? '',
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalContainedIn' => 0,
            ];

            // CPU as component
            $cpuModel = $sysInfo['cpuModel'] ?? $sysInfo['processorType'] ?? 'CPU';
            $inventory[] = [
                'entPhysicalIndex' => 2,
                'entPhysicalDescr' => $cpuModel,
                'entPhysicalClass' => 'cpu',
                'entPhysicalName' => 'System CPU',
                'entPhysicalContainedIn' => 1,
            ];

            // Memory as component
            $totalMem = $sysInfo['memoryTotal'] ?? $sysInfo['totalMemory'] ?? 0;
            if ($totalMem > 0) {
                $inventory[] = [
                    'entPhysicalIndex' => 3,
                    'entPhysicalDescr' => 'System Memory - ' . $this->formatBytes($totalMem),
                    'entPhysicalClass' => 'memory',
                    'entPhysicalName' => 'RAM',
                    'entPhysicalContainedIn' => 1,
                ];
            }

            // Storage as component
            $totalDisk = $sysInfo['diskTotal'] ?? $sysInfo['totalDisk'] ?? 0;
            if ($totalDisk > 0) {
                $inventory[] = [
                    'entPhysicalIndex' => 4,
                    'entPhysicalDescr' => 'Internal Storage - ' . $this->formatBytes($totalDisk),
                    'entPhysicalClass' => 'disk',
                    'entPhysicalName' => 'Storage',
                    'entPhysicalContainedIn' => 1,
                ];
            }
        }

        // Get license info
        $licenseInfo = $this->get($this->apiPath('license/smartlicenses'));
        if (! empty($licenseInfo['items'])) {
            $licIdx = 100;
            foreach ($licenseInfo['items'] as $license) {
                $inventory[] = [
                    'entPhysicalIndex' => $licIdx++,
                    'entPhysicalDescr' => $license['name'] ?? $license['licenseName'] ?? 'License',
                    'entPhysicalClass' => 'other',
                    'entPhysicalName' => 'License: ' . ($license['type'] ?? 'Feature'),
                    'entPhysicalSerialNum' => $license['licenseKey'] ?? '',
                    'entPhysicalContainedIn' => 1,
                ];
            }
        }

        Log::debug('FTD: Fetched inventory', [
            'device_id' => $this->device->device_id,
            'count' => count($inventory),
        ]);

        return $inventory;
    }

    /**
     * Fetch storage/disk information
     */
    public function fetchStorage(Device $device): array
    {
        $storage = [];

        // Get disk stats
        $diskStats = $this->get($this->apiPath('operational/diskstats'));

        if (! empty($diskStats['items'])) {
            foreach ($diskStats['items'] as $idx => $disk) {
                $total = $disk['total'] ?? $disk['size'] ?? 0;
                $used = $disk['used'] ?? 0;
                $free = $disk['free'] ?? $disk['available'] ?? ($total - $used);

                $storage[] = [
                    'storage_index' => $idx,
                    'storage_type' => 'hrStorageFixedDisk',
                    'storage_descr' => $disk['mountPoint'] ?? $disk['name'] ?? $disk['path'] ?? "Disk {$idx}",
                    'storage_size' => $total,
                    'storage_used' => $used,
                    'storage_free' => $free,
                    'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                    'storage_units' => 1, // bytes
                ];
            }
        } elseif (isset($diskStats['diskTotal']) || isset($diskStats['total'])) {
            // Single disk
            $total = $diskStats['diskTotal'] ?? $diskStats['total'] ?? 0;
            $used = $diskStats['diskUsed'] ?? $diskStats['used'] ?? 0;
            $free = $diskStats['diskFree'] ?? $diskStats['free'] ?? ($total - $used);

            $storage[] = [
                'storage_index' => 0,
                'storage_type' => 'hrStorageFixedDisk',
                'storage_descr' => '/dev/sda',
                'storage_size' => $total,
                'storage_used' => $used,
                'storage_free' => $free,
                'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                'storage_units' => 1,
            ];
        }

        Log::debug('FTD: Fetched storage', [
            'device_id' => $this->device->device_id,
            'count' => count($storage),
        ]);

        return $storage;
    }

    /**
     * Fetch transceivers (not typically available on FTD)
     */
    public function fetchTransceivers(Device $device): array
    {
        return [];
    }

    /**
     * Fetch IPv4 addresses
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        $addresses = [];

        // Get physical interfaces with IP addresses
        $physicalInterfaces = $this->get($this->apiPath('object/physicalinterfaces'));
        foreach ($physicalInterfaces['items'] ?? [] as $iface) {
            if (! empty($iface['ipv4']) && ! empty($iface['ipv4']['ipAddress'])) {
                $ip = $iface['ipv4']['ipAddress']['ipAddress'] ?? null;
                $mask = $iface['ipv4']['ipAddress']['netmask'] ?? '255.255.255.0';

                if ($ip) {
                    $addresses[] = [
                        'ipv4_address' => $ip,
                        'ipv4_prefixlen' => $this->netmaskToPrefixLen($mask),
                        'port_id' => null, // Will be linked via ifName
                        'ifName' => $iface['name'] ?? $iface['hardwareName'] ?? '',
                    ];
                }
            }
        }

        // Get sub-interfaces
        $subInterfaces = $this->get($this->apiPath('object/subinterfaces'));
        foreach ($subInterfaces['items'] ?? [] as $iface) {
            if (! empty($iface['ipv4']) && ! empty($iface['ipv4']['ipAddress'])) {
                $ip = $iface['ipv4']['ipAddress']['ipAddress'] ?? null;
                $mask = $iface['ipv4']['ipAddress']['netmask'] ?? '255.255.255.0';

                if ($ip) {
                    $addresses[] = [
                        'ipv4_address' => $ip,
                        'ipv4_prefixlen' => $this->netmaskToPrefixLen($mask),
                        'port_id' => null,
                        'ifName' => $iface['name'] ?? '',
                    ];
                }
            }
        }

        Log::debug('FTD: Fetched IPv4 addresses', [
            'device_id' => $this->device->device_id,
            'count' => count($addresses),
        ]);

        return $addresses;
    }

    /**
     * Fetch port/interface statistics
     */
    public function fetchPortsStatistics(Device $device): array
    {
        $stats = [];

        // Get interface statistics
        $ifaceStats = $this->get($this->apiPath('operational/interfacestats'));

        foreach ($ifaceStats['items'] ?? [] as $stat) {
            $ifName = $stat['interfaceName'] ?? $stat['name'] ?? '';
            if (empty($ifName)) {
                continue;
            }

            $stats[] = [
                'ifName' => $ifName,
                'ifInOctets' => $stat['bytesInput'] ?? $stat['rxBytes'] ?? 0,
                'ifOutOctets' => $stat['bytesOutput'] ?? $stat['txBytes'] ?? 0,
                'ifInUcastPkts' => $stat['packetsInput'] ?? $stat['rxPackets'] ?? 0,
                'ifOutUcastPkts' => $stat['packetsOutput'] ?? $stat['txPackets'] ?? 0,
                'ifInErrors' => $stat['inputErrors'] ?? $stat['rxErrors'] ?? 0,
                'ifOutErrors' => $stat['outputErrors'] ?? $stat['txErrors'] ?? 0,
                'ifInDiscards' => $stat['inputDrops'] ?? 0,
                'ifOutDiscards' => $stat['outputDrops'] ?? 0,
            ];
        }

        Log::debug('FTD: Fetched port statistics', [
            'device_id' => $this->device->device_id,
            'count' => count($stats),
        ]);

        return $stats;
    }

    /**
     * Fetch VMs (not applicable for FTD)
     */
    public function fetchVms(Device $device): array
    {
        return [];
    }

    // =========================================================================
    // Normalization Helpers
    // =========================================================================

    /**
     * Normalize CPU stats to sensors
     */
    protected function normalizeCpuSensors(array $cpuStats): array
    {
        $sensors = [];

        if (! empty($cpuStats['items'])) {
            foreach ($cpuStats['items'] as $idx => $cpu) {
                $usage = $cpu['used'] ?? $cpu['usage'] ?? $cpu['cpuUsage'] ?? null;
                if ($usage !== null) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'ftd-cpu',
                        'sensor_descr' => $cpu['name'] ?? "CPU {$idx}",
                        'sensor_index' => "ftd_cpu_{$idx}",
                        'sensor_current' => $usage,
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        } elseif (isset($cpuStats['cpu5minAvg']) || isset($cpuStats['cpuUsage'])) {
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'ftd-cpu',
                'sensor_descr' => 'CPU Usage (5 min avg)',
                'sensor_index' => 'ftd_cpu_5min',
                'sensor_current' => $cpuStats['cpu5minAvg'] ?? $cpuStats['cpuUsage'] ?? 0,
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        return $sensors;
    }

    /**
     * Normalize memory stats to sensors
     */
    protected function normalizeMemorySensors(array $memStats): array
    {
        $sensors = [];

        $total = $memStats['memoryTotal'] ?? $memStats['total'] ?? 0;
        $used = $memStats['memoryUsed'] ?? $memStats['used'] ?? 0;

        if ($total > 0) {
            $usagePercent = round(($used / $total) * 100, 2);

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'ftd-memory',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'ftd_memory_usage',
                'sensor_current' => $usagePercent,
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        return $sensors;
    }

    /**
     * Normalize disk stats to sensors
     */
    protected function normalizeDiskSensors(array $diskStats): array
    {
        $sensors = [];

        if (! empty($diskStats['items'])) {
            foreach ($diskStats['items'] as $idx => $disk) {
                $total = $disk['total'] ?? $disk['size'] ?? 0;
                $used = $disk['used'] ?? 0;

                if ($total > 0) {
                    $usagePercent = round(($used / $total) * 100, 2);
                    $name = $disk['mountPoint'] ?? $disk['name'] ?? "Disk {$idx}";

                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'ftd-disk',
                        'sensor_descr' => "Disk Usage - {$name}",
                        'sensor_index' => 'ftd_disk_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($name)),
                        'sensor_current' => $usagePercent,
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        } elseif (isset($diskStats['diskTotal']) || isset($diskStats['total'])) {
            $total = $diskStats['diskTotal'] ?? $diskStats['total'] ?? 0;
            $used = $diskStats['diskUsed'] ?? $diskStats['used'] ?? 0;

            if ($total > 0) {
                $usagePercent = round(($used / $total) * 100, 2);

                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'ftd-disk',
                    'sensor_descr' => 'Disk Usage',
                    'sensor_index' => 'ftd_disk_usage',
                    'sensor_current' => $usagePercent,
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }

    /**
     * Normalize metrics to sensors
     */
    protected function normalizeMetricSensors(array $metrics): array
    {
        $sensors = [];

        foreach ($metrics['items'] ?? [] as $metric) {
            $type = $metric['metricType'] ?? $metric['type'] ?? '';
            $name = $metric['name'] ?? $type;
            $value = $metric['value'] ?? $metric['currentValue'] ?? null;

            if ($value === null) {
                continue;
            }

            // Connection counts
            if (stripos($type, 'connection') !== false) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'ftd-connections',
                    'sensor_descr' => $name,
                    'sensor_index' => 'ftd_conn_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($type)),
                    'sensor_current' => $value,
                ];
            }

            // Throughput
            if (stripos($type, 'throughput') !== false || stripos($type, 'bps') !== false) {
                $sensors[] = [
                    'sensor_class' => 'bitrate',
                    'sensor_type' => 'ftd-throughput',
                    'sensor_descr' => $name,
                    'sensor_index' => 'ftd_throughput_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($type)),
                    'sensor_current' => $value,
                ];
            }

            // Packet rates
            if (stripos($type, 'packet') !== false || stripos($type, 'pps') !== false) {
                $sensors[] = [
                    'sensor_class' => 'rate',
                    'sensor_type' => 'ftd-packets',
                    'sensor_descr' => $name,
                    'sensor_index' => 'ftd_pps_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($type)),
                    'sensor_current' => $value,
                ];
            }
        }

        return $sensors;
    }

    /**
     * Normalize HA status to sensors
     */
    protected function normalizeHaSensors(array $haStatus): array
    {
        $sensors = [];

        // HA state sensor
        $state = $haStatus['state'] ?? $haStatus['haState'] ?? null;
        if ($state !== null) {
            // Map state to numeric value for graphing
            $stateMap = [
                'active' => 1,
                'standby' => 2,
                'disabled' => 0,
                'failed' => -1,
                'unknown' => -2,
            ];
            $stateValue = $stateMap[strtolower($state)] ?? -2;

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'ftd-ha',
                'sensor_descr' => 'HA State',
                'sensor_index' => 'ftd_ha_state',
                'sensor_current' => $stateValue,
                'sensor_extra' => ['state_text' => $state],
            ];
        }

        return $sensors;
    }

    /**
     * Normalize interface to port structure
     */
    protected function normalizeInterface(array $iface, int $ifIndex, string $type = 'physical'): array
    {
        $adminStatus = $iface['enabled'] ?? $iface['adminState'] ?? true;
        $operStatus = $iface['linkState'] ?? $iface['operState'] ?? 'unknown';

        // Map status to standard values
        $adminUp = ($adminStatus === true || strtolower((string) $adminStatus) === 'up' || $adminStatus === 1);
        $operUp = (strtolower((string) $operStatus) === 'up');

        // Determine speed
        $speed = $iface['speed'] ?? $iface['linkSpeed'] ?? 0;
        if (is_string($speed)) {
            // Parse speed strings like "1000mbps", "10g"
            if (preg_match('/(\d+)\s*(g|gbps)/i', $speed, $m)) {
                $speed = (int) $m[1] * 1000000000;
            } elseif (preg_match('/(\d+)\s*(m|mbps)/i', $speed, $m)) {
                $speed = (int) $m[1] * 1000000;
            } else {
                $speed = (int) $speed;
            }
        }

        return [
            'ifIndex' => $ifIndex,
            'ifName' => $iface['name'] ?? $iface['hardwareName'] ?? "interface{$ifIndex}",
            'ifDescr' => $iface['description'] ?? $iface['name'] ?? "Interface {$ifIndex}",
            'ifAlias' => $iface['securityZone']['name'] ?? $iface['zoneName'] ?? '',
            'ifType' => $this->getIfType($type, $iface),
            'ifSpeed' => $speed,
            'ifMtu' => $iface['mtu'] ?? 1500,
            'ifPhysAddress' => $iface['macAddress'] ?? $iface['mac'] ?? null,
            'ifAdminStatus' => $adminUp ? 'up' : 'down',
            'ifOperStatus' => $operUp ? 'up' : 'down',
            'ifDuplex' => $iface['duplex'] ?? 'auto',
        ];
    }

    /**
     * Get interface type
     */
    protected function getIfType(string $type, array $iface): string
    {
        $ifTypes = [
            'physical' => 'ethernetCsmacd',
            'subinterface' => 'l2vlan',
            'etherchannel' => 'ieee8023adLag',
            'bridgegroup' => 'bridge',
            'tunnel' => 'tunnel',
            'loopback' => 'softwareLoopback',
        ];

        return $ifTypes[$type] ?? 'other';
    }

    /**
     * Convert netmask to prefix length
     */
    protected function netmaskToPrefixLen(string $netmask): int
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return 24; // Default
        }
        $base = ip2long('255.255.255.255');

        return 32 - (int) log(($long ^ $base) + 1, 2);
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor] ?? 'B');
    }

    // =========================================================================
    // Utility Methods
    // =========================================================================

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
     * Get API information
     */
    public function getApiInfo(): array
    {
        return [
            'vendor' => 'Cisco',
            'product' => 'Firepower Threat Defense (FTD)',
            'api_type' => 'REST API with OAuth 2.0',
            'version' => $this->apiVersion,
            'authenticated' => ! empty($this->accessToken),
            'token_expires' => $this->tokenExpiry ? date('Y-m-d H:i:s', $this->tokenExpiry) : null,
        ];
    }
}
