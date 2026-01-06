<?php

namespace App\ApiClients\Fortinet;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\ApiClients\TestableDevice;
use App\Models\Device;
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

    protected Device|TestableDevice $device;
    protected DeviceHttpClient $httpClient;

    public function __construct(Device|TestableDevice $device)
    {
        $this->device = $device;

        // Build HTTP client with API token auth
        $httpOptions = DeviceApiSettings::httpOptions($device);
        if (empty($httpOptions['base_url'])) {
            throw new \RuntimeException("No base URL configured for FortiGate device {$device->device_id}");
        }

        // Get API token from device attributes (try multiple field names for compatibility, decrypt if encrypted)
        $apiToken = DeviceApiSettings::getCredential($device, 'api_credential_api_token')
                 ?? DeviceApiSettings::getCredential($device, 'api_credential_token')
                 ?? DeviceApiSettings::getCredential($device, 'api_credential_access_token');

        if (!$apiToken) {
            throw new \RuntimeException("API token required for FortiGate authentication");
        }

        // FortiGate devices can be slow on some endpoints, use higher default timeout
        $timeout = $httpOptions['timeout_ms'] ?? 15000; // 15 seconds default for FortiGate
        if ($timeout < 10000) {
            Log::debug("FortiGate timeout increased from {$timeout}ms to 10000ms");
            $timeout = 10000; // Minimum 10 seconds for FortiGate
        }

        // Create HTTP client with Bearer token
        $this->httpClient = new DeviceHttpClient([
            'base_url' => $httpOptions['base_url'],
            'headers' => array_merge($httpOptions['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $apiToken,
            ]),
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $timeout,
            'max_retries' => 3, // Retry up to 3 times for timeouts
            'proxy' => $httpOptions['proxy'] ?? null,
        ], $device);

        Log::debug("FortiGate client initialized", [
            'device_id' => $device->device_id,
            'base_url' => $httpOptions['base_url'],
        ]);
    }

    public function supports(Device|TestableDevice $device): bool
    {
        // Support FortiGate devices with API config
        if (!in_array($device->os, ['fortigate', 'fortinet'], true)) {
            return false;
        }

        return $device->getAttrib('api_base_url') !== null;
    }

    public function capabilities(): array
    {
        return ['inventory', 'ports', 'sensors', 'processors', 'mempools', 'storage', 'transceivers', 'ipv4', 'ports_stats'];
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
     * These fetch methods are also used as a REST fallback to keep IPv4 mapping consistent.
     */
    public function fetchSensors(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchPorts(Device|TestableDevice $device): array
    {
        $ports = [];

        try {
            $response = $this->get('/cmdb/system/interface');
            $interfaces = $response['results'] ?? [];

            if (!is_array($interfaces)) {
                return [];
            }

            foreach ($interfaces as $idx => $iface) {
                $name = $iface['name'] ?? (is_string($idx) ? $idx : "port$idx");
                $ifIndex = $this->stableIndexFromName($name);

                $linkUp = $iface['link'] ?? false;
                $status = $linkUp ? 'up' : 'down';
                if (isset($iface['status'])) {
                    $status = strtolower($iface['status']) === 'up' ? 'up' : 'down';
                }

                $speed = $iface['speed'] ?? 1000;
                $speedBps = is_numeric($speed) ? ((int) $speed) * 1000000 : 1000000000;

                $alias = $iface['alias'] ?? '';
                $ifDescr = $alias !== '' ? $alias : $name;

                $macAddr = $iface['mac'] ?? $iface['macaddr'] ?? '';

                $ports[] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $name,
                    'ifDescr' => $ifDescr,
                    'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                    'ifSpeed' => $speedBps,
                    'ifOperStatus' => $status,
                    'ifAdminStatus' => $status,
                    'ifMtu' => $iface['mtu'] ?? 1500,
                    'ifPhysAddress' => $macAddr,
                    'ifAlias' => $alias,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('FortiGate fetchPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    public function fetchMempools(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchProcessors(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchInventory(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchStorage(Device|TestableDevice $device): array
    {
        $storage = [];

        try {
            // Get system disk information
            $response = $this->get('/monitor/system/storage');
            $disks = $response['results'] ?? [];

            foreach ($disks as $idx => $disk) {
                $name = $disk['name'] ?? "disk-$idx";
                $size = $disk['size'] ?? 0;
                $used = $disk['used'] ?? 0;
                $free = $size - $used;

                $storage[] = [
                    'storage_index' => "disk-$idx",
                    'storage_descr' => $name,
                    'storage_type' => $disk['type'] ?? 'fortigate-storage',
                    'storage_size' => $size,
                    'storage_used' => $used,
                    'storage_free' => $free,
                    'storage_units' => 1,
                    'storage_perc' => $size > 0 ? round(($used / $size) * 100, 2) : 0,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('FortiGate fetchStorage failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    public function fetchTransceivers(Device|TestableDevice $device): array
    {
        $transceivers = [];

        try {
            // Get interface transceivers/SFP information
            $response = $this->get('/monitor/system/interface/transceiver');
            $results = $response['results'] ?? [];

            $numericIdx = 0;
            foreach ($results as $idx => $sfp) {
                $portName = $sfp['name'] ?? (is_string($idx) ? $idx : "port-$idx");
                $numericIdx++;

                // Only include if SFP is present
                if (isset($sfp['present']) && $sfp['present']) {
                    $transceivers[] = [
                        'ifIndex' => $numericIdx,
                        'port_descr_type' => $sfp['type'] ?? 'SFP',
                        'port_descr_descr' => $portName,
                        'port_descr_speed' => $sfp['speed'] ?? '',
                        'port_descr_vendor' => $sfp['vendor'] ?? '',
                        'port_descr_part' => $sfp['part_number'] ?? '',
                        'port_descr_serial' => $sfp['serial'] ?? '',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('FortiGate fetchTransceivers failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $transceivers;
    }

    public function fetchIpv4Addresses(Device|TestableDevice $device): array
    {
        $addresses = [];

        try {
            // Get interface IP addresses
            $response = $this->get('/cmdb/system/interface');
            $interfaces = $response['results'] ?? [];

            foreach ($interfaces as $idx => $iface) {
                $ifName = $iface['name'] ?? (is_string($idx) ? $idx : "port$idx");
                $ifIndex = $this->stableIndexFromName($ifName);
                $ip = $iface['ip'] ?? null;

                if ($ip && strpos($ip, '/') !== false) {
                    // Format: 192.168.1.1/24
                    list($ipAddr, $prefixLen) = explode('/', $ip);

                    if (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $addresses[] = [
                            'ifIndex' => $ifIndex,
                            'ifName' => $ifName,
                            'ipv4_address' => $ipAddr,
                            'ipv4_prefixlen' => (int)$prefixLen,
                            'context_name' => 'fortigate',
                        ];
                    }
                } elseif ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Just an IP without CIDR
                    $addresses[] = [
                        'ifIndex' => $ifIndex,
                        'ifName' => $ifName,
                        'ipv4_address' => $ip,
                        'ipv4_prefixlen' => 24, // Default
                        'context_name' => 'fortigate',
                    ];
                }

                // Also check for secondary IPs
                if (!empty($iface['secondaryip'])) {
                    foreach ($iface['secondaryip'] as $secIdx => $secIp) {
                        $secIpAddr = $secIp['ip'] ?? null;
                        if ($secIpAddr && strpos($secIpAddr, '/') !== false) {
                            list($secAddr, $secPrefix) = explode('/', $secIpAddr);

                            if (filter_var($secAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                $addresses[] = [
                                    'ifIndex' => $ifIndex,
                                    'ifName' => $ifName,
                                    'ipv4_address' => $secAddr,
                                    'ipv4_prefixlen' => (int)$secPrefix,
                                    'context_name' => 'fortigate',
                                ];
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            Log::warning('FortiGate fetchIpv4Addresses failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    public function fetchPortsStatistics(Device|TestableDevice $device): array
    {
        $stats = [];

        try {
            // Get interface statistics
            $response = $this->get('/monitor/system/interface');
            $interfaces = $response['results'] ?? [];

            foreach ($interfaces as $idx => $iface) {
                $ifName = $iface['name'] ?? (is_string($idx) ? $idx : "port$idx");
                $ifIndex = $this->stableIndexFromName($ifName);

                // Extract statistics if available
                $stats[] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $ifName,
                    'ifInOctets' => $iface['rx_bytes'] ?? 0,
                    'ifOutOctets' => $iface['tx_bytes'] ?? 0,
                    'ifInErrors' => $iface['rx_errors'] ?? 0,
                    'ifOutErrors' => $iface['tx_errors'] ?? 0,
                    'ifInUcastPkts' => $iface['rx_packets'] ?? 0,
                    'ifOutUcastPkts' => $iface['tx_packets'] ?? 0,
                    'ifInDiscards' => $iface['rx_dropped'] ?? 0,
                    'ifOutDiscards' => $iface['tx_dropped'] ?? 0,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('FortiGate fetchPortsStatistics failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    public function isReachable(): bool
    {
        try {
            // Try to get system status (path relative to base_url which already includes /api/v2)
            $this->get('/monitor/system/status');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            // Get FortiGate system info (path relative to base_url which already includes /api/v2)
            $response = $this->get('/monitor/system/status');
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

    public function fetchVms(Device|TestableDevice $device): array
    {
        // FortiGate firewalls do not host virtual machines
        return [];
    }

    protected function stableIndexFromName(string $name): int
    {
        return abs(crc32($name)) % 2147483647;
    }
}
