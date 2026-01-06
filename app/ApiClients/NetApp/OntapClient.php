<?php

namespace App\ApiClients\NetApp;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\TestableDevice;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;

/**
 * NetApp ONTAP REST API Client
 *
 * Implements full monitoring capabilities for NetApp ONTAP systems.
 * API Documentation: https://library.netapp.com/ecmdocs/ECMLP2856304/html/index.html
 */
class OntapClient implements DeviceApiClientInterface
{
    public const VENDOR = 'netapp_ontap';

    protected Device|TestableDevice $device;
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected bool $verifyTls;
    protected int $timeout;

    public function __construct(Device|TestableDevice $device)
    {
        $this->device = $device;

        $http = DeviceApiSettings::httpOptions($device);
        $this->baseUrl = rtrim($http['base_url'], '/');
        $this->verifyTls = $http['verify_tls'];
        $this->timeout = (int) ($http['timeout_ms'] / 1000) ?: 30;

        // Read credentials from device attributes (decrypt if encrypted)
        $this->username = DeviceApiSettings::getCredential($device, 'api_credential_username') ?? '';
        $this->password = DeviceApiSettings::getCredential($device, 'api_credential_password') ?? '';
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password)
            ->withOptions(['verify' => $this->verifyTls])
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $response = $this->http()->get($url, $query);

        if ($response->failed()) {
            throw new \RuntimeException("NetApp GET $path failed: " . $response->status());
        }

        return $response->json() ?? [];
    }

    public function post(string $path, array $body = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $response = $this->http()->post($url, $body);

        if ($response->failed()) {
            throw new \RuntimeException("NetApp POST $path failed: " . $response->status());
        }

        return $response->json() ?? [];
    }

    public function supports(Device|TestableDevice $device): bool
    {
        return $device->os === 'netapp' && $device->getAttrib('api_base_url') !== null;
    }

    public function capabilities(): array
    {
        return [
            'sensors',
            'ports',
            'processors',
            'mempools',
            'inventory',
            'storage',
            'ipv4',
            'ports_stats',
        ];
    }

    /**
     * Fetch cluster information
     */
    public function fetchClusterInfo(): array
    {
        try {
            return $this->get('/cluster');
        } catch (\Exception $e) {
            Log::warning('NetApp fetchClusterInfo failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch cluster nodes
     */
    public function fetchNodes(): array
    {
        try {
            return $this->get('/cluster/nodes', ['fields' => '*']);
        } catch (\Exception $e) {
            Log::warning('NetApp fetchNodes failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch sensors - combines multiple sources
     */
    public function fetchSensors(Device|TestableDevice $device): array
    {
        $sensors = [];

        try {
            // Get cluster health status
            $cluster = $this->fetchClusterInfo();
            if (!empty($cluster)) {
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'netapp',
                    'sensor_descr' => 'Cluster Health',
                    'sensor_index' => 'cluster_health',
                    'sensor_current' => ($cluster['metric']['status'] ?? 'ok') === 'ok' ? 1 : 0,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'error'],
                        ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'ok'],
                    ],
                ];
            }

            // Get node health and metrics
            $nodes = $this->fetchNodes();
            foreach ($nodes['records'] ?? [] as $node) {
                $name = $node['name'] ?? 'node';
                $index = abs(crc32($name)) % 2147483647;

                // Node state sensor
                $state = $node['state'] ?? 'unknown';
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'netapp',
                    'sensor_descr' => "Node $name State",
                    'sensor_index' => "node_state_$index",
                    'sensor_current' => $state === 'up' ? 1 : 0,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'down'],
                        ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'up'],
                    ],
                ];

                // Uptime sensor
                if (isset($node['uptime'])) {
                    $sensors[] = [
                        'sensor_class' => 'runtime',
                        'sensor_type' => 'netapp',
                        'sensor_descr' => "Node $name Uptime",
                        'sensor_index' => "node_uptime_$index",
                        'sensor_current' => $node['uptime'],
                    ];
                }
            }

            // Get aggregate health
            $aggregates = $this->get('/storage/aggregates', ['fields' => 'name,state,space']);
            foreach ($aggregates['records'] ?? [] as $aggr) {
                $name = $aggr['name'] ?? 'aggregate';
                $index = abs(crc32($name)) % 2147483647;
                $space = $aggr['space'] ?? [];
                $used = $space['block_storage']['used'] ?? 0;
                $size = $space['block_storage']['size'] ?? 0;

                if ($size > 0) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'netapp',
                        'sensor_descr' => "Aggregate $name Used",
                        'sensor_index' => "aggr_used_$index",
                        'sensor_current' => round(($used / $size) * 100, 2),
                        'sensor_limit' => 90,
                    ];
                }
            }

            // Get disk health
            $disks = $this->get('/storage/disks', ['fields' => 'name,state,type']);
            $diskStates = ['normal' => 0, 'failed' => 0, 'unknown' => 0];
            foreach ($disks['records'] ?? [] as $disk) {
                $state = strtolower($disk['state'] ?? 'unknown');
                if ($state === 'present' || $state === 'normal') {
                    $diskStates['normal']++;
                } elseif ($state === 'failed' || $state === 'broken') {
                    $diskStates['failed']++;
                } else {
                    $diskStates['unknown']++;
                }
            }

            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'netapp',
                'sensor_descr' => 'Disks Normal',
                'sensor_index' => 'disks_normal',
                'sensor_current' => $diskStates['normal'],
            ];

            if ($diskStates['failed'] > 0) {
                $sensors[] = [
                    'sensor_class' => 'count',
                    'sensor_type' => 'netapp',
                    'sensor_descr' => 'Disks Failed',
                    'sensor_index' => 'disks_failed',
                    'sensor_current' => $diskStates['failed'],
                    'sensor_limit' => 1,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchSensors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    /**
     * Fetch network ports
     */
    public function fetchPorts(Device|TestableDevice $device): array
    {
        $ports = [];

        try {
            // Get ethernet ports
            $ethPorts = $this->get('/network/ethernet/ports', ['fields' => '*']);

            foreach ($ethPorts['records'] ?? [] as $port) {
                $name = $port['name'] ?? 'port';
                $node = $port['node']['name'] ?? '';
                $fullName = $node ? "$node:$name" : $name;
                $index = abs(crc32($fullName)) % 2147483647;

                $speed = $port['speed'] ?? 0;
                // Convert speed to bits per second
                if (is_string($speed)) {
                    // Handle speed values like "10g", "1000", "auto", etc.
                    if (preg_match('/^(\d+)(g|gb)?$/i', $speed, $matches)) {
                        $speed = (int) $matches[1] * 1000000000; // Gbps to bps
                    } elseif (is_numeric($speed)) {
                        $speed = (int) $speed * 1000000; // Mbps to bps
                    } else {
                        $speed = 0;
                    }
                } elseif (is_int($speed)) {
                    $speed = $speed * 1000000; // Mbps to bps
                }

                $state = strtolower($port['state'] ?? 'down');
                $enabled = $port['enabled'] ?? true;

                $ports[] = [
                    'ifIndex' => $index,
                    'ifName' => $fullName,
                    'ifDescr' => $port['broadcast_domain']['name'] ?? $fullName,
                    'ifAlias' => $port['type'] ?? '',
                    'ifType' => 'ethernetCsmacd',
                    'ifSpeed' => $speed,
                    'ifOperStatus' => $state === 'up' ? 'up' : 'down',
                    'ifAdminStatus' => $enabled ? 'up' : 'down',
                    'ifMtu' => $port['mtu'] ?? 1500,
                    'ifPhysAddress' => $port['mac_address'] ?? '',
                ];
            }

            // Get FC ports if available
            try {
                $fcPorts = $this->get('/network/fc/ports', ['fields' => '*']);
                foreach ($fcPorts['records'] ?? [] as $port) {
                    $name = $port['name'] ?? 'fc_port';
                    $node = $port['node']['name'] ?? '';
                    $fullName = $node ? "$node:$name" : $name;
                    $index = abs(crc32($fullName)) % 2147483647;

                    // Get FC speed - could be "auto" or a number
                    $fcSpeed = $port['speed']['configured'] ?? $port['speed']['maximum'] ?? 8;
                    if (!is_numeric($fcSpeed)) {
                        $fcSpeed = $port['speed']['maximum'] ?? 8;
                    }
                    if (!is_numeric($fcSpeed)) {
                        $fcSpeed = 8; // Default to 8Gbps
                    }

                    $ports[] = [
                        'ifIndex' => $index,
                        'ifName' => $fullName,
                        'ifDescr' => 'FC Port: ' . $fullName,
                        'ifAlias' => 'Fibre Channel',
                        'ifType' => 'fibreChannel',
                        'ifSpeed' => (int) $fcSpeed * 1000000000, // Gbps to bps
                        'ifOperStatus' => ($port['state'] ?? 'offline') === 'online' ? 'up' : 'down',
                        'ifAdminStatus' => ($port['enabled'] ?? true) ? 'up' : 'down',
                        'ifPhysAddress' => $port['wwpn'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                // FC ports may not be available on all systems
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Fetch processors (CPU metrics from nodes)
     */
    public function fetchProcessors(Device|TestableDevice $device): array
    {
        $processors = [];

        try {
            // Get node metrics with CPU info
            $nodes = $this->get('/cluster/nodes', [
                'fields' => 'name,controller.cpu.processor,controller.cpu.count',
            ]);

            foreach ($nodes['records'] ?? [] as $idx => $node) {
                $name = $node['name'] ?? "node$idx";
                $cpuInfo = $node['controller']['cpu'] ?? [];

                $processors[] = [
                    'processor_index' => $idx,
                    'processor_type' => 'netapp-cpu',
                    'processor_descr' => "Node $name CPU" .
                        (isset($cpuInfo['processor']) ? ' (' . $cpuInfo['processor'] . ')' : ''),
                    'processor_usage' => null, // ONTAP doesn't expose real-time CPU %
                ];
            }

            // Try to get CPU utilization from system/nodes endpoint if available
            try {
                $nodeMetrics = $this->get('/cluster/metrics', ['fields' => '*']);
                if (isset($nodeMetrics['processor_utilization'])) {
                    // Update first processor with overall CPU usage
                    if (!empty($processors)) {
                        $processors[0]['processor_usage'] = round($nodeMetrics['processor_utilization'], 2);
                    }
                }
            } catch (\Exception $e) {
                // Metrics endpoint may not be available
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchProcessors failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    /**
     * Fetch memory pools
     */
    public function fetchMempools(Device|TestableDevice $device): array
    {
        $mempools = [];

        try {
            $nodes = $this->get('/cluster/nodes', [
                'fields' => 'name,controller.memory_size',
            ]);

            foreach ($nodes['records'] ?? [] as $idx => $node) {
                $name = $node['name'] ?? "node$idx";
                $memSize = $node['controller']['memory_size'] ?? 0;

                if ($memSize > 0) {
                    $mempools[] = [
                        'mempool_index' => $idx,
                        'mempool_type' => 'netapp',
                        'mempool_descr' => "Node $name Memory",
                        'mempool_total' => $memSize,
                        'mempool_used' => 0, // ONTAP doesn't expose memory usage
                        'mempool_free' => $memSize,
                        'mempool_perc' => 0,
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchMempools failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    /**
     * Fetch inventory (chassis, nodes, shelves, disks)
     */
    public function fetchInventory(Device|TestableDevice $device): array
    {
        $inventory = [];
        $containerIndex = 1;

        try {
            // Cluster as top-level container
            $cluster = $this->fetchClusterInfo();
            if (!empty($cluster)) {
                $inventory[] = [
                    'entPhysicalIndex' => $containerIndex,
                    'entPhysicalDescr' => 'NetApp Cluster: ' . ($cluster['name'] ?? 'cluster'),
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => $cluster['name'] ?? 'cluster',
                    'entPhysicalModelName' => 'ONTAP Cluster',
                    'entPhysicalSerialNum' => $cluster['uuid'] ?? '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'NetApp',
                    'entPhysicalSoftwareRev' => $cluster['version']['full'] ?? '',
                    'entPhysicalIsFRU' => 0,
                ];
            }

            // Nodes
            $nodes = $this->fetchNodes();
            foreach ($nodes['records'] ?? [] as $node) {
                $containerIndex++;
                $name = $node['name'] ?? 'node';

                $inventory[] = [
                    'entPhysicalIndex' => $containerIndex,
                    'entPhysicalDescr' => 'Controller Node: ' . $name,
                    'entPhysicalClass' => 'module',
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => $node['model'] ?? '',
                    'entPhysicalSerialNum' => $node['serial_number'] ?? '',
                    'entPhysicalContainedIn' => 1,
                    'entPhysicalMfgName' => 'NetApp',
                    'entPhysicalSoftwareRev' => $node['version']['full'] ?? '',
                    'entPhysicalIsFRU' => 1,
                ];
            }

            // Disk shelves
            try {
                $shelves = $this->get('/storage/shelves', ['fields' => '*']);
                foreach ($shelves['records'] ?? [] as $shelf) {
                    $containerIndex++;
                    $name = $shelf['name'] ?? $shelf['id'] ?? 'shelf';

                    $inventory[] = [
                        'entPhysicalIndex' => $containerIndex,
                        'entPhysicalDescr' => 'Disk Shelf: ' . $name,
                        'entPhysicalClass' => 'container',
                        'entPhysicalName' => $name,
                        'entPhysicalModelName' => $shelf['model'] ?? '',
                        'entPhysicalSerialNum' => $shelf['serial_number'] ?? '',
                        'entPhysicalContainedIn' => 1,
                        'entPhysicalMfgName' => 'NetApp',
                        'entPhysicalIsFRU' => 1,
                    ];
                }
            } catch (\Exception $e) {
                // Shelves endpoint may not be available
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchInventory failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    /**
     * Fetch storage (volumes and aggregates)
     */
    public function fetchStorage(Device|TestableDevice $device): array
    {
        $storage = [];

        try {
            // Get volumes
            $volumes = $this->get('/storage/volumes', [
                'fields' => 'name,space,svm.name,state',
            ]);

            foreach ($volumes['records'] ?? [] as $vol) {
                $name = $vol['name'] ?? 'volume';
                $svm = $vol['svm']['name'] ?? '';
                $fullName = $svm ? "$svm:$name" : $name;
                $space = $vol['space'] ?? [];
                $size = $space['size'] ?? 0;
                $used = $space['used'] ?? 0;

                if ($size > 0) {
                    $storage[] = [
                        'storage_index' => abs(crc32($fullName)) % 2147483647,
                        'storage_type' => 'netapp-volume',
                        'storage_descr' => "Volume: $fullName",
                        'storage_size' => $size,
                        'storage_used' => $used,
                        'storage_free' => $size - $used,
                        'storage_perc' => round(($used / $size) * 100, 2),
                        'storage_units' => 1,
                    ];
                }
            }

            // Get aggregates
            $aggregates = $this->get('/storage/aggregates', [
                'fields' => 'name,space,state',
            ]);

            foreach ($aggregates['records'] ?? [] as $aggr) {
                $name = $aggr['name'] ?? 'aggregate';
                $space = $aggr['space']['block_storage'] ?? [];
                $size = $space['size'] ?? 0;
                $used = $space['used'] ?? 0;

                if ($size > 0) {
                    $storage[] = [
                        'storage_index' => abs(crc32("aggr_$name")) % 2147483647,
                        'storage_type' => 'netapp-aggregate',
                        'storage_descr' => "Aggregate: $name",
                        'storage_size' => $size,
                        'storage_used' => $used,
                        'storage_free' => $size - $used,
                        'storage_perc' => round(($used / $size) * 100, 2),
                        'storage_units' => 1,
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchStorage failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    /**
     * Fetch transceivers (not applicable for storage)
     */
    public function fetchTransceivers(Device|TestableDevice $device): array
    {
        return [];
    }

    /**
     * Fetch IPv4 addresses
     */
    public function fetchIpv4Addresses(Device|TestableDevice $device): array
    {
        $addresses = [];

        try {
            $interfaces = $this->get('/network/ip/interfaces', [
                'fields' => 'name,ip.address,ip.netmask,location.port.name,location.node.name',
            ]);

            foreach ($interfaces['records'] ?? [] as $iface) {
                $ip = $iface['ip'] ?? [];
                $addr = $ip['address'] ?? null;
                $netmask = $ip['netmask'] ?? '255.255.255.0';

                if ($addr && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $portName = $iface['location']['port']['name'] ?? '';
                    $nodeName = $iface['location']['node']['name'] ?? '';
                    $ifName = $nodeName && $portName ? "$nodeName:$portName" : ($portName ?: $iface['name'] ?? 'lif');

                    // Calculate prefix length from netmask
                    $prefixLen = 24;
                    if ($netmask) {
                        $long = ip2long($netmask);
                        if ($long !== false) {
                            $prefixLen = 0;
                            for ($i = 0; $i < 32; $i++) {
                                if (($long & (1 << (31 - $i))) !== 0) {
                                    $prefixLen++;
                                } else {
                                    break;
                                }
                            }
                        }
                    }

                    $addresses[] = [
                        'ifIndex' => abs(crc32($ifName)) % 2147483647,
                        'ifName' => $ifName,
                        'ipv4_address' => $addr,
                        'ipv4_prefixlen' => $prefixLen,
                        'context_name' => '',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchIpv4Addresses failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    /**
     * Fetch port statistics
     */
    public function fetchPortsStatistics(Device|TestableDevice $device): array
    {
        $stats = [];

        try {
            $ports = $this->get('/network/ethernet/ports', [
                'fields' => 'name,node.name,statistics',
            ]);

            foreach ($ports['records'] ?? [] as $port) {
                $name = $port['name'] ?? 'port';
                $node = $port['node']['name'] ?? '';
                $fullName = $node ? "$node:$name" : $name;
                $portStats = $port['statistics'] ?? [];

                $stats[] = [
                    'ifIndex' => abs(crc32($fullName)) % 2147483647,
                    'ifInOctets' => $portStats['received']['bytes'] ?? 0,
                    'ifOutOctets' => $portStats['transmitted']['bytes'] ?? 0,
                    'ifInErrors' => $portStats['received']['errors'] ?? 0,
                    'ifOutErrors' => $portStats['transmitted']['errors'] ?? 0,
                    'ifInUcastPkts' => $portStats['received']['packets'] ?? 0,
                    'ifOutUcastPkts' => $portStats['transmitted']['packets'] ?? 0,
                ];
            }

        } catch (\Exception $e) {
            Log::warning('NetApp fetchPortsStatistics failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Fetch VMs (not applicable for storage)
     */
    public function fetchVms(Device|TestableDevice $device): array
    {
        return [];
    }

    /**
     * Check if API is reachable
     */
    public function isReachable(): bool
    {
        try {
            $this->fetchClusterInfo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get API information
     */
    public function getApiInfo(): array
    {
        try {
            $cluster = $this->fetchClusterInfo();
            return [
                'vendor' => 'netapp',
                'api_version' => $cluster['version']['full'] ?? 'unknown',
                'cluster_name' => $cluster['name'] ?? 'unknown',
                'reachable' => true,
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => 'netapp',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
