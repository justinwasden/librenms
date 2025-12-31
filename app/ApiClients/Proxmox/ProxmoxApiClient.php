<?php
namespace App\ApiClients\Proxmox;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
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

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Get HTTP options from DeviceApiSettings
        $http = DeviceApiSettings::httpOptions($device);
        $this->base = rtrim($http['base_url'], '/');
        $this->timeout = (int)$http['timeout_ms'];
        $this->verifyTls = (bool)$http['verify_tls'];
        $this->proxy = $http['proxy'] ?? null;

        // Determine auth type from schema key
        $schemaKey = $device->getAttrib('api_auth_schema', 'proxmox_token');
        $this->authType = str_contains($schemaKey, 'ticket') ? 'ticket' : 'token';

        if ($this->authType === 'token') {
            // Read token credentials from device attributes (decrypt if encrypted)
            $user = DeviceApiSettings::getCredential($device, 'api_credential_token_user') ?? '';
            $tokenid = DeviceApiSettings::getCredential($device, 'api_credential_token_id') ?? '';
            $secret = DeviceApiSettings::getCredential($device, 'api_credential_token_secret') ?? '';

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
        // Read username/password credentials from device attributes (decrypt if encrypted)
        $user = DeviceApiSettings::getCredential($this->device, 'api_credential_username') ?? '';
        $password = DeviceApiSettings::getCredential($this->device, 'api_credential_password') ?? '';

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
        $domain = parse_url($this->base, PHP_URL_HOST) ?? '';

        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        // Only add cookies if we have them and a valid domain
        if (!empty($this->cookies) && !empty($domain)) {
            $req = $req->withCookies($this->cookies, $domain);
        }

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
        return $device->os === 'proxmox' && $device->getAttrib('api_base_url') !== null;
    }

    public function capabilities(): array
    {
        return ['sensors', 'ports', 'processors', 'mempools', 'storage', 'ipv4', 'ports_stats', 'vminfo', 'clusters', 'hypervisor_hosts'];
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

            // Build a map of interface names for consistent indexing and deduplication
            // Proxmox API may return multiple entries for the same interface with different IPs
            $interfaceData = [];
            foreach ($interfaces as $interface) {
                $ifName = trim($interface['iface'] ?? '');
                if (!$ifName || strtolower($ifName) === 'lo') {
                    continue;
                }

                // Extract MAC address from altnames if not in hwaddr
                // Proxmox often stores MAC in altnames like "enx0025b518a0ed"
                $hwaddr = $interface['hwaddr'] ?? '';
                if (empty($hwaddr) && !empty($interface['altnames'])) {
                    foreach ($interface['altnames'] as $altname) {
                        // Check if altname starts with "enx" followed by 12 hex chars (MAC)
                        if (preg_match('/^enx([0-9a-f]{12})$/i', $altname, $matches)) {
                            $hwaddr = $matches[1];
                            break;
                        }
                    }
                }

                // Parse bridge_ports for additional info
                $bridgePorts = [];
                if (!empty($interface['bridge_ports'])) {
                    $bridgePorts = array_map('trim', explode(' ', $interface['bridge_ports']));
                }

                // Initialize or merge interface data
                if (!isset($interfaceData[$ifName])) {
                    $interfaceData[$ifName] = [
                        'iface' => $ifName,
                        'type' => $interface['type'] ?? 'unknown',
                        'active' => $interface['active'] ?? 0,
                        'autostart' => $interface['autostart'] ?? 0,
                        'mtu' => $interface['mtu'] ?? 1500,
                        'hwaddr' => $hwaddr,
                        'comments' => $interface['comments'] ?? '',
                        'bridge_ports' => $bridgePorts,
                        'vlan_id' => $interface['vlan-id'] ?? '',
                        'vlan_raw_device' => $interface['vlan-raw-device'] ?? '',
                        'altnames' => $interface['altnames'] ?? [],
                    ];
                } else {
                    // Merge data: prefer non-empty values
                    if (empty($interfaceData[$ifName]['hwaddr']) && !empty($hwaddr)) {
                        $interfaceData[$ifName]['hwaddr'] = $hwaddr;
                    }
                    if (empty($interfaceData[$ifName]['comments']) && !empty($interface['comments'])) {
                        $interfaceData[$ifName]['comments'] = $interface['comments'];
                    } elseif (!empty($interface['comments']) && strlen($interface['comments']) > strlen($interfaceData[$ifName]['comments'])) {
                        $interfaceData[$ifName]['comments'] = $interface['comments'];
                    }
                    if (($interface['active'] ?? 0) && !$interfaceData[$ifName]['active']) {
                        $interfaceData[$ifName]['active'] = 1;
                    }
                    if (($interface['autostart'] ?? 0) && !$interfaceData[$ifName]['autostart']) {
                        $interfaceData[$ifName]['autostart'] = 1;
                    }
                    if (($interface['mtu'] ?? 0) > ($interfaceData[$ifName]['mtu'] ?? 0)) {
                        $interfaceData[$ifName]['mtu'] = $interface['mtu'];
                    }
                    if ($interfaceData[$ifName]['type'] === 'unknown' && !empty($interface['type'])) {
                        $interfaceData[$ifName]['type'] = $interface['type'];
                    }
                    if (empty($interfaceData[$ifName]['bridge_ports']) && !empty($bridgePorts)) {
                        $interfaceData[$ifName]['bridge_ports'] = $bridgePorts;
                    }
                    if (empty($interfaceData[$ifName]['vlan_id']) && !empty($interface['vlan-id'])) {
                        $interfaceData[$ifName]['vlan_id'] = $interface['vlan-id'];
                    }
                    if (empty($interfaceData[$ifName]['vlan_raw_device']) && !empty($interface['vlan-raw-device'])) {
                        $interfaceData[$ifName]['vlan_raw_device'] = $interface['vlan-raw-device'];
                    }
                    if (empty($interfaceData[$ifName]['altnames']) && !empty($interface['altnames'])) {
                        $interfaceData[$ifName]['altnames'] = $interface['altnames'];
                    }
                }
            }

            // Create ports from merged interface data
            foreach ($interfaceData as $ifName => $iface) {
                $type = $iface['type'];
                $macAddress = '';
                if (!empty($iface['hwaddr'])) {
                    $macAddress = strtolower(trim($iface['hwaddr']));
                    // Normalize MAC address format
                    $macAddress = preg_replace('/[^0-9a-f]/i', '', $macAddress);
                    if (strlen($macAddress) === 12) {
                        $macAddress = implode(':', str_split($macAddress, 2));
                    }
                }

                // Build description with type and additional info
                $description = $ifName;
                $descParts = [];

                if ($type && $type !== 'unknown') {
                    $descParts[] = ucfirst($type);
                }

                // Add VLAN info for VLAN interfaces
                if ($type === 'vlan' && !empty($iface['vlan_id'])) {
                    $descParts[] = "VLAN {$iface['vlan_id']}";
                    if (!empty($iface['vlan_raw_device'])) {
                        $descParts[] = "on {$iface['vlan_raw_device']}";
                    }
                }

                // Add bridge ports for bridges
                if ($type === 'bridge' && !empty($iface['bridge_ports'])) {
                    $descParts[] = 'ports: ' . implode(', ', $iface['bridge_ports']);
                }

                // Add comment if available
                $comment = trim($iface['comments']);
                if (!empty($comment)) {
                    $descParts[] = $comment;
                }

                if (!empty($descParts)) {
                    $description = $ifName . ' (' . implode(', ', $descParts) . ')';
                }

                // Determine interface type
                $ifType = 'ethernetCsmacd';
                if ($type === 'bridge') {
                    $ifType = 'bridge';
                } elseif ($type === 'bond') {
                    $ifType = 'ieee8023adLag';
                } elseif ($type === 'vlan') {
                    $ifType = 'l2vlan';
                } elseif ($type === 'eth') {
                    $ifType = 'ethernetCsmacd';
                }

                // Determine port speed - default to 10Gbps if MTU is 9000 (jumbo frames often used for 10G)
                $ifSpeed = 1000000000; // 1Gbps default
                if ($iface['mtu'] >= 9000) {
                    $ifSpeed = 10000000000; // 10Gbps for jumbo frame interfaces
                }

                $ports[] = [
                    'ifIndex' => $this->stableIndexFromName($ifName),
                    'ifName' => $ifName,
                    'ifDescr' => $description,
                    'ifAlias' => $comment,
                    'ifType' => $ifType,
                    'ifOperStatus' => $iface['active'] ? 'up' : 'down',
                    'ifAdminStatus' => $iface['autostart'] ? 'up' : 'down',
                    'ifSpeed' => $ifSpeed,
                    'ifMtu' => $iface['mtu'],
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
     * Generate stable ifIndex from interface name
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

            foreach ($interfaces as $interface) {
                $ifName = trim($interface['iface'] ?? '');
                if (!$ifName || strtolower($ifName) === 'lo') {
                    continue;
                }

                // Proxmox API can return multiple IP addresses for the same interface
                // Process all entries to capture all IPs
                $cidr = $interface['cidr'] ?? null;
                $address = $interface['address'] ?? null;
                $netmask = $interface['netmask'] ?? null;

                // If cidr field exists and contains a slash, parse it
                if ($cidr && strpos($cidr, '/') !== false) {
                    [$address, $prefixLenStr] = explode('/', $cidr, 2);
                    $prefixlen = (int) $prefixLenStr;
                } elseif ($address) {
                    // Calculate prefix length from netmask
                    $prefixlen = 24; // Default safe value
                    
                    if ($netmask) {
                        if (is_numeric($netmask)) {
                            // Netmask is already a CIDR prefix length
                            $prefixlen = (int) $netmask;
                        } else {
                            // Netmask is a dotted quad (e.g., "255.255.255.0")
                            try {
                                $prefixlen = $this->netmaskToPrefixlen($netmask);
                            } catch (\Exception $e) {
                                // If conversion fails, use default
                                $prefixlen = 24;
                            }
                        }
                    }
                } else {
                    // No IP address information for this interface entry
                    continue;
                }

                // Validate IP address and add it
                if ($address && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Use stable index for consistency with port matching
                    // But also include ifName for persistor to match by name (more reliable)
                    $addresses[] = [
                        'ifIndex' => $this->stableIndexFromName($ifName),
                        'ifName' => $ifName,
                        'ipv4_address' => $address,
                        'ipv4_prefixlen' => $prefixlen,
                        'context_name' => '',
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

    public function fetchVms(Device $device): array
    {
        $vms = [];

        try {
            // Get cluster resources filtered by VM/LXC type
            $resources = $this->get('cluster/resources?type=vm');
            $data = $resources['data'] ?? [];

            foreach ($data as $resource) {
                $type = $resource['type'] ?? '';

                // Skip non-VM resources
                if (!in_array($type, ['qemu', 'lxc'])) {
                    continue;
                }

                // Map Proxmox VM states to LibreNMS vminfo states
                $status = $resource['status'] ?? 'unknown';
                $vmState = match($status) {
                    'running' => 'running',
                    'stopped' => 'poweredOff',
                    'paused' => 'suspended',
                    default => 'unknown',
                };

                // Extract VM details
                $vmid = $resource['vmid'] ?? $resource['id'] ?? null;
                if (!$vmid) {
                    continue;
                }

                $vms[] = [
                    'vm_type' => 'proxmox',
                    'vmwVmVMID' => (string) $vmid,
                    'vmwVmDisplayName' => $resource['name'] ?? "VM-{$vmid}",
                    'vmwVmGuestOS' => $type === 'lxc' ? 'Linux Container' : ($resource['ostype'] ?? 'Other'),
                    'vmwVmMemSize' => isset($resource['maxmem']) ? (int) ($resource['maxmem'] / (1024 * 1024)) : null, // Convert bytes to MB
                    'vmwVmCpus' => isset($resource['maxcpu']) ? (int) $resource['maxcpu'] : ($resource['cpus'] ?? null),
                    'vmwVmState' => $vmState,
                    'vmwVmHostId' => $resource['node'] ?? null, // Capture the node/host where VM is running
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('Proxmox fetchVms failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vms;
    }

    /**
     * Fetch Proxmox cluster information
     *
     * @param Device $device
     * @return array
     */
    public function fetchClusters(Device $device): array
    {
        $clusters = [];

        try {
            // Get cluster status
            $response = $this->get('cluster/status');

            if (empty($response)) {
                return [];
            }

            foreach ($response as $item) {
                $type = $item['type'] ?? null;

                // The first item with type 'cluster' represents the cluster itself
                if ($type === 'cluster') {
                    $clusters[] = [
                        'cluster_type' => 'proxmox',
                        'cluster_id' => $item['name'] ?? 'proxmox-cluster',
                        'cluster_name' => $item['name'] ?? 'Proxmox Cluster',
                        'parent_id' => null,
                        'parent_name' => null,
                        'cluster_level' => 'cluster',
                        'metadata' => [
                            'quorate' => $item['quorate'] ?? null,
                            'nodes' => $item['nodes'] ?? null,
                            'version' => $item['version'] ?? null,
                        ],
                    ];
                    break; // Only one cluster entry
                }
            }

            // If no cluster found, create a default standalone entry
            if (empty($clusters)) {
                $clusters[] = [
                    'cluster_type' => 'proxmox',
                    'cluster_id' => 'standalone',
                    'cluster_name' => 'Standalone Node',
                    'parent_id' => null,
                    'parent_name' => null,
                    'cluster_level' => 'cluster',
                    'metadata' => [],
                ];
            }

        } catch (\Exception $e) {
            Log::warning('ProxmoxApiClient fetchClusters failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $clusters;
    }

    /**
     * Fetch Proxmox nodes (hosts)
     *
     * @param Device $device
     * @return array
     */
    public function fetchHosts(Device $device): array
    {
        $hosts = [];

        try {
            // Get node list
            $nodes = $this->get('nodes');

            if (empty($nodes)) {
                return [];
            }

            foreach ($nodes as $node) {
                $nodeName = $node['node'] ?? null;
                if (!$nodeName) {
                    continue;
                }

                // Get detailed node status
                $nodeStatus = null;
                try {
                    $nodeStatus = $this->get("nodes/{$nodeName}/status");
                } catch (\Exception $e) {
                    Log::debug("ProxmoxApiClient: Could not fetch status for node {$nodeName}: {$e->getMessage()}");
                }

                // Map Proxmox status to our status values
                $status = $node['status'] ?? 'unknown';
                $status = match(strtolower($status)) {
                    'online' => 'connected',
                    'offline' => 'disconnected',
                    default => strtolower($status),
                };

                // Determine role - check if this is the node we're connected to
                $role = 'node';
                $currentNode = $device->getAttrib('proxmox_node');
                if ($currentNode && $currentNode === $nodeName) {
                    $role = 'master'; // The node we're managing from
                }

                $cpuCores = $nodeStatus['cpuinfo']['cpus'] ?? ($node['maxcpu'] ?? null);
                $memoryTotal = $nodeStatus['memory']['total'] ?? ($node['maxmem'] ?? null);
                $version = $nodeStatus['pveversion'] ?? null;

                $hosts[] = [
                    'host_type' => 'proxmox-node',
                    'host_id' => $nodeName,
                    'host_name' => $nodeName,
                    'cluster_id' => null, // Will be populated based on cluster name if available
                    'role' => $role,
                    'status' => $status,
                    'version' => $version,
                    'cpu_cores' => $cpuCores,
                    'cpu_threads' => null, // Proxmox doesn't expose thread count directly
                    'memory_total' => $memoryTotal,
                    'ip_address' => $node['ip'] ?? null,
                    'metadata' => [
                        'uptime' => $node['uptime'] ?? null,
                        'level' => $node['level'] ?? null,
                    ],
                ];
            }

        } catch (\Exception $e) {
            Log::warning('ProxmoxApiClient fetchHosts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $hosts;
    }
}