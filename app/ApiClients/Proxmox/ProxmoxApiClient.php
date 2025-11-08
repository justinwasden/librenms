<?php
namespace App\ApiClients\Proxmox;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class ProxmoxApiClient implements DeviceApiClientInterface
{
    public const VENDOR = 'proxmox';
    protected Device $device;
    protected string $base;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;
    protected string $authType;
    protected array $headers = [];
    protected array $cookies = [];
    protected ?DeviceApiConfig $apiConfig = null;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config from database
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('schema.fields')->where('device_id', $device->device_id)->first();

        // Get HTTP options from DeviceApiSettings
        $http = DeviceApiSettings::httpOptions($device);
        $this->base = rtrim($http['base_url'], '/');
        $this->timeout = (int)$http['timeout_ms'];
        $this->verifyTls = (bool)$http['verify_tls'];
        $this->proxy = $http['proxy'] ?? null;

        // Determine auth type from schema key
        $schemaKey = $this->apiConfig?->schema?->key ?? 'proxmox_token';
        $this->authType = str_contains($schemaKey, 'ticket') ? 'ticket' : 'token';

        if ($this->authType === 'token') {
            $user = $this->apiConfig?->getValue('token_user') ?? '';
            $tokenid = $this->apiConfig?->getValue('token_id') ?? '';
            $secret = $this->apiConfig?->getValue('token_secret') ?? '';

            // Validate required token fields
            if (empty($user) || empty($tokenid) || empty($secret)) {
                $debugInfo = sprintf(
                    'Proxmox API token authentication requires token_user, token_id, and token_secret. Got: user=%s, tokenid=%s, secret=%s',
                    $user ? 'SET' : 'EMPTY',
                    $tokenid ? 'SET' : 'EMPTY',
                    $secret ? 'SET' : 'EMPTY'
                );
                throw new \RuntimeException($debugInfo);
            }

            $authHeader = "PVEAPIToken={$user}!{$tokenid}={$secret}";
            $this->headers['Authorization'] = $authHeader;

            // Log for debugging (mask the secret)
            $maskedSecret = substr($secret, 0, 8) . '...' . substr($secret, -4);
            \Log::debug('Proxmox Auth Header', [
                'user' => $user,
                'tokenid' => $tokenid,
                'secret_preview' => $maskedSecret,
                'header_format' => "PVEAPIToken={$user}!{$tokenid}=[SECRET]",
            ]);
        } else {
            $this->login(); // sets cookie/header
        }
    }

    protected function login(): void
    {
        $user = $this->apiConfig?->getValue('username') ?? '';
        $password = $this->apiConfig?->getValue('password') ?? '';

        $resp = Http::timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls])
            ->post($this->base . '/access/ticket', ['username' => $user, 'password' => $password]);

        if ($resp->failed()) {
            throw new \RuntimeException('Proxmox login failed: ' . $resp->status());
        }
        $data = $resp->json()['data'] ?? [];
        $ticket = $data['ticket'] ?? '';
        $csrf = $data['CSRFPreventionToken'] ?? '';
        $this->cookies = ['PVEAuthCookie' => $ticket];
        if ($csrf) {
            $this->headers['CSRFPreventionToken'] = $csrf;
        }
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withHeaders($this->headers)
            ->withCookies($this->cookies, parse_url($this->base, PHP_URL_HOST))
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        return $req;
    }

    public function get(string $path, array $query = []): array
    {
        // Extract query string from path if present (e.g., "nodes/pve/rrddata?timeframe=hour")
        $parsedQuery = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $parsedQuery);
        }

        // Merge extracted query with provided query (provided takes precedence)
        $query = array_merge($parsedQuery, $query);

        $uri = rtrim($this->base, '/') . '/' . ltrim($path, '/');
        $resp = $this->http()->get($uri, $query);
        if ($resp->failed()) {
            $body = $resp->body();
            $errorDetail = $body ? " - Response: $body" : '';
            $authHeader = isset($this->headers['Authorization']) ? 'Auth header present' : 'No auth header';
            throw new \RuntimeException("Proxmox GET $uri failed: " . $resp->status() . " ($authHeader)" . $errorDetail);
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    public function post(string $path, array $body = []): array
    {
        $uri = rtrim($this->base, '/') . '/' . ltrim($path, '/');
        $resp = $this->http()->post($uri, $body);
        if ($resp->failed()) {
            throw new \RuntimeException("Proxmox POST $path failed: " . $resp->status());
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    // Optional helpers (not required by executor but useful)
    public function getNodes(): array { return $this->get('nodes'); }
    public function getNodeStatus(string $node): array { return $this->get("nodes/{$node}/status"); }
    public function getNodeNetwork(string $node): array { return $this->get("nodes/{$node}/network"); }
    public function getClusterStatus(): array { return $this->get('cluster/status'); }

    public function supports(Device $device): bool
    {
        return $device->os === 'proxmox' && $this->apiConfig !== null;
    }

    public function capabilities(): array
    {
        return ['sensors', 'ports', 'processors', 'mempools', 'storage', 'ipv4', 'ports_stats'];
    }

    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            // Get cluster resources for overall stats
            $resources = $this->get('cluster/resources');
            $data = $resources['data'] ?? [];

            foreach ($data as $resource) {
                $type = $resource['type'] ?? '';
                $node = $resource['node'] ?? 'unknown';
                $name = $resource['name'] ?? $resource['id'] ?? 'unknown';

                if ($type === 'node') {
                    // CPU usage
                    if (isset($resource['cpu'])) {
                        $sensors[] = [
                            'sensor_class' => 'load',
                            'sensor_type' => 'proxmox',
                            'sensor_descr' => "$node CPU Usage",
                            'sensor_current' => $resource['cpu'] * 100,
                            'sensor_limit' => 100,
                        ];
                    }

                    // Memory usage
                    if (isset($resource['mem']) && isset($resource['maxmem'])) {
                        $usagePercent = ($resource['maxmem'] > 0) ? ($resource['mem'] / $resource['maxmem']) * 100 : 0;
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'proxmox',
                            'sensor_descr' => "$node Memory Usage",
                            'sensor_current' => $usagePercent,
                            'sensor_limit' => 100,
                        ];
                    }

                    // Disk usage
                    if (isset($resource['disk']) && isset($resource['maxdisk'])) {
                        $usagePercent = ($resource['maxdisk'] > 0) ? ($resource['disk'] / $resource['maxdisk']) * 100 : 0;
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'proxmox',
                            'sensor_descr' => "$node Disk Usage",
                            'sensor_current' => $usagePercent,
                            'sensor_limit' => 100,
                        ];
                    }

                    // Uptime
                    if (isset($resource['uptime'])) {
                        $sensors[] = [
                            'sensor_class' => 'runtime',
                            'sensor_type' => 'proxmox',
                            'sensor_descr' => "$node Uptime",
                            'sensor_current' => $resource['uptime'],
                        ];
                    }
                } elseif ($type === 'storage') {
                    // Storage usage
                    if (isset($resource['disk']) && isset($resource['maxdisk'])) {
                        $usagePercent = ($resource['maxdisk'] > 0) ? ($resource['disk'] / $resource['maxdisk']) * 100 : 0;
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'proxmox',
                            'sensor_descr' => "$node Storage $name Usage",
                            'sensor_current' => $usagePercent,
                            'sensor_limit' => 100,
                        ];
                    }
                }
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchSensors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    public function fetchPorts(Device $device): array
    {
        $ports = [];

        try {
            // Get node name from cluster resources
            $resources = $this->get('cluster/resources');
            $nodes = array_filter($resources['data'] ?? [], fn($r) => ($r['type'] ?? '') === 'node');

            if (empty($nodes)) {
                return [];
            }

            $node = reset($nodes)['node'] ?? null;
            if (!$node) {
                return [];
            }

            // Get network interfaces for the node
            $network = $this->get("nodes/$node/network");
            $interfaces = $network['data'] ?? [];

            // Build a map of interface names for consistent indexing
            $ifNameToIndex = [];
            foreach ($interfaces as $idx => $interface) {
                $ifName = $interface['iface'] ?? "port$idx";
                if ($ifName !== 'lo') {
                    $ifNameToIndex[$ifName] = $this->stableIndexFromName($ifName);
                }
            }

            foreach ($interfaces as $idx => $interface) {
                $ifName = $interface['iface'] ?? "port$idx";
                $type = $interface['type'] ?? 'unknown';

                // Skip loopback
                if ($ifName === 'lo') {
                    continue;
                }

                // Proxmox /network endpoint doesn't return MAC addresses
                // We'll need to use ifName for identification instead
                $macAddress = '';

                $ports[] = [
                    'ifIndex' => $ifNameToIndex[$ifName],
                    'ifName' => $ifName,
                    'ifDescr' => $ifName,
                    'ifAlias' => $interface['comments'] ?? '',
                    'ifType' => $type === 'bridge' ? 'bridge' : ($type === 'bond' ? 'bond' : 'ethernetCsmacd'),
                    'ifOperStatus' => (isset($interface['active']) && $interface['active']) ? 'up' : 'down',
                    'ifAdminStatus' => (isset($interface['autostart']) && $interface['autostart']) ? 'up' : 'down',
                    'ifSpeed' => 1000000000, // Default to 1Gbps
                    'ifMtu' => $interface['mtu'] ?? 1500,
                    'ifPhysAddress' => $macAddress,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Generate stable ifIndex from interface name (same as RestNormalizers)
     */
    protected function stableIndexFromName(string $name): int
    {
        return abs(crc32($name)) % 2147483647;
    }

    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        try {
            $resources = $this->get('cluster/resources');
            $nodes = array_filter($resources['data'] ?? [], fn($r) => ($r['type'] ?? '') === 'node');

            foreach ($nodes as $idx => $node) {
                $nodeName = $node['node'] ?? "node$idx";

                if (isset($node['mem']) && isset($node['maxmem'])) {
                    $mempools[] = [
                        'mempool_index' => $idx,
                        'mempool_type' => 'proxmox',
                        'mempool_descr' => "$nodeName Memory",
                        'mempool_precision' => 1,
                        'mempool_used' => $node['mem'],
                        'mempool_total' => $node['maxmem'],
                        'mempool_free' => $node['maxmem'] - $node['mem'],
                        'mempool_perc' => ($node['maxmem'] > 0) ? ($node['mem'] / $node['maxmem']) * 100 : 0,
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchMempools failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        try {
            $resources = $this->get('cluster/resources');
            $nodes = array_filter($resources['data'] ?? [], fn($r) => ($r['type'] ?? '') === 'node');

            foreach ($nodes as $idx => $node) {
                $nodeName = $node['node'] ?? "node$idx";

                if (isset($node['cpu']) && isset($node['maxcpu'])) {
                    $processors[] = [
                        'processor_index' => $idx,
                        'processor_type' => 'proxmox',
                        'processor_descr' => "$nodeName CPU",
                        'processor_usage' => $node['cpu'] * 100,
                        'processor_precision' => 1,
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchProcessors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    public function fetchInventory(Device $device): array
    {
        return [];
    }

    public function fetchStorage(Device $device): array
    {
        $storage = [];

        try {
            // Get cluster resources for storage information
            $resources = $this->get('cluster/resources');
            $data = $resources['data'] ?? [];

            foreach ($data as $idx => $resource) {
                $type = $resource['type'] ?? '';

                if ($type === 'storage') {
                    $node = $resource['node'] ?? 'unknown';
                    $name = $resource['storage'] ?? $resource['id'] ?? "storage$idx";
                    $disk = $resource['disk'] ?? 0;
                    $maxdisk = $resource['maxdisk'] ?? 0;

                    $storage[] = [
                        'storage_index' => 'storage-' . $idx,
                        'storage_descr' => "$node:$name",
                        'storage_type' => $resource['content'] ?? 'proxmox-storage',
                        'storage_size' => $maxdisk,
                        'storage_used' => $disk,
                        'storage_free' => max(0, $maxdisk - $disk),
                        'storage_units' => 1,
                        'storage_perc' => $maxdisk > 0 ? round(($disk / $maxdisk) * 100, 2) : 0,
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchStorage failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    public function fetchTransceivers(Device $device): array
    {
        // Proxmox is a virtualization platform, transceivers not applicable
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        $addresses = [];

        try {
            // Get cluster resources to find nodes
            $resources = $this->get('cluster/resources');
            $nodes = array_filter($resources['data'] ?? [], fn($r) => ($r['type'] ?? '') === 'node');

            if (empty($nodes)) {
                return [];
            }

            $node = reset($nodes)['node'] ?? null;
            if (!$node) {
                return [];
            }

            // Get network interfaces for the node
            $network = $this->get("nodes/$node/network");
            $interfaces = $network['data'] ?? [];

            foreach ($interfaces as $idx => $interface) {
                $ifName = $interface['iface'] ?? "port$idx";

                // Skip loopback
                if ($ifName === 'lo') {
                    continue;
                }

                // Parse IP address and CIDR
                $address = $interface['address'] ?? null;
                $netmask = $interface['netmask'] ?? null;
                $cidr = $interface['cidr'] ?? null;

                if ($address) {
                    // Calculate prefix length
                    $prefixlen = 24; // default

                    if ($cidr && strpos($cidr, '/') !== false) {
                        // CIDR format: 192.168.1.1/24
                        $parts = explode('/', $cidr);
                        $prefixlen = (int)($parts[1] ?? 24);
                    } elseif ($netmask) {
                        // Check if netmask is already a number (CIDR)
                        if (is_numeric($netmask)) {
                            $prefixlen = (int)$netmask;
                        } else {
                            // Convert netmask to prefix length
                            $prefixlen = $this->netmaskToPrefixlen($netmask);
                        }
                    }

                    // Use ifName for lookup instead of ifIndex
                    $addresses[] = [
                        'ifName' => $ifName,  // Use ifName for port resolution
                        'ipv4_address' => $address,
                        'ipv4_prefixlen' => $prefixlen,
                        'context_name' => 'proxmox',
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchIpv4Addresses failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    public function fetchPortsStatistics(Device $device): array
    {
        $stats = [];

        try {
            // Get cluster resources to find nodes
            $resources = $this->get('cluster/resources');
            $nodes = array_filter($resources['data'] ?? [], fn($r) => ($r['type'] ?? '') === 'node');

            if (empty($nodes)) {
                return [];
            }

            $node = reset($nodes)['node'] ?? null;
            if (!$node) {
                return [];
            }

            // Get network interfaces
            $network = $this->get("nodes/$node/network");
            $interfaces = $network['data'] ?? [];

            // Try to get RRD data for network statistics
            foreach ($interfaces as $idx => $interface) {
                $ifName = $interface['iface'] ?? "port$idx";

                // Skip loopback
                if ($ifName === 'lo') {
                    continue;
                }

                // Proxmox provides netin/netout in the node status
                // We'll use basic interface status for now
                $active = isset($interface['active']) && $interface['active'] ? 1 : 0;

                $stats[] = [
                    'ifIndex' => $idx + 1,
                    'ifInOctets' => 0,  // Would need RRD data
                    'ifOutOctets' => 0, // Would need RRD data
                    'ifInErrors' => 0,
                    'ifOutErrors' => 0,
                    'ifInUcastPkts' => 0,
                    'ifOutUcastPkts' => 0,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchPortsStatistics failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Convert dotted decimal netmask to CIDR prefix length
     */
    protected function netmaskToPrefixlen(string $netmask): int
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return 24;
        }

        $cidr = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($long & (1 << (31 - $i))) !== 0) {
                $cidr++;
            } else {
                break;
            }
        }

        return $cidr;
    }

    public function isReachable(): bool
    {
        try {
            $this->get('cluster/status');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            $data = $this->get('version');
            return [
                'vendor' => 'proxmox',
                'api_version' => $data['data']['version'] ?? 'unknown',
                'reachable' => true,
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => 'proxmox',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}