<?php

namespace App\ApiClients\PureStorage;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\AuthStrategies\AuthStrategyFactory;
use App\ApiClients\AuthStrategies\AuthContext;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class FlashArrayClient implements DeviceApiClientInterface
{
    public const VENDOR = 'purestorage';
    protected Device $device;
    protected array $httpBaseOpts;
    protected array $requestOpts = [];
    protected ?AuthContext $authCtx = null;
    protected ?DeviceApiConfig $apiConfig = null;

    public function __construct(Device $device, array $template = [])
    {
        $this->device = $device;

        // Load API config from database
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('schema.fields')->where('device_id', $device->device_id)->first();

        $http = DeviceApiSettings::httpOptions($device);
        $values = $this->resolveValues();

        $strategyKey = $template['strategy_key'] ?? $this->apiConfig?->schema?->key ?? 'pure_token_login';
        $strategyOpts = array_merge($template['strategy_options'] ?? [], [
            'base_url'   => $http['base_url'],
            'verify_ssl' => $http['verify_tls'],
            'timeout_ms' => $http['timeout_ms'],
            'proxy'      => $http['proxy'] ?? null,
            'values'     => $values,
        ]);

        // Map schema fields to strategy expectations for Pure login
        if (in_array($strategyKey, ['pure_token_login', 'purestorage_api_token_login'], true)) {
            $strategyOpts['login_url'] = $strategyOpts['login_url'] ?? ($http['base_url'] . ($values['login_path'] ?? '/login'));
            $strategyOpts['login_header_key'] = $strategyOpts['login_header_key'] ?? 'api-token';
            $v = $strategyOpts['values'] ?? [];
            $strategyOpts['values'] = array_merge($v, [
                'api_login_header_value' => $v['api_token'] ?? $v['api_login_header_value'] ?? null,
            ]);
            if (!isset($strategyOpts['session_header_key']) && isset($v['auth_header_name'])) {
                $strategyOpts['session_header_key'] = $v['auth_header_name'];
            }
        }

        $strategy = AuthStrategyFactory::make($strategyKey);
        $this->authCtx = $strategy->authenticate($device, $strategyOpts);

        $this->requestOpts = $strategy->apply([
            'headers' => $http['headers'] ?? [],
            'verify'  => $http['verify_tls'],
            'timeout' => $http['timeout_ms'] / 1000,
        ], $this->authCtx);

        $this->httpBaseOpts = [
            'base_uri' => rtrim($http['base_url'], '/') . '/',
            'verify'   => $http['verify_tls'],
            'timeout'  => $http['timeout_ms'] / 1000,
        ];
        if (!empty($http['proxy'])) {
            $this->httpBaseOpts['proxy'] = $http['proxy'];
        }
    }

    protected function client()
    {
        $req = Http::withOptions($this->httpBaseOpts)
            ->withHeaders($this->requestOpts['headers'] ?? []);

        if (!empty($this->requestOpts['_cookies'])) {
            $host = parse_url($this->httpBaseOpts['base_uri'] ?? '', PHP_URL_HOST) ?: '';
            $req = $req->withCookies($this->requestOpts['_cookies'], $host);
        }

        return $req;
    }

    public function get(string $path, array $query = []): array
    {
        $resp = $this->client()->get(ltrim($path, '/'), $query);
        if ($resp->failed()) {
            throw new \RuntimeException("Pure GET $path failed: " . $resp->status());
        }
        return $resp->json() ?: [];
    }

    public function post(string $path, array $body = []): array
    {
        $resp = $this->client()->post(ltrim($path, '/'), $body);
        if ($resp->failed()) {
            throw new \RuntimeException("Pure POST $path failed: " . $resp->status());
        }
        return $resp->json() ?: [];
    }

    protected function resolveValues(): array
    {
        if (!$this->apiConfig) {
            return [
                'api_token' => null,
                'auth_header_name' => 'X-Auth-Token',
                'login_path' => '/login',
            ];
        }

        $apiToken = $this->apiConfig->getValue('api_token') ?? $this->apiConfig->getValue('api_key');
        $authHeaderName = $this->apiConfig->getValue('auth_header_name');
        $loginPath = $this->apiConfig->getValue('login_path');

        // Handle null values explicitly
        if (empty($authHeaderName)) {
            $authHeaderName = 'X-Auth-Token';
        }
        if (empty($loginPath)) {
            $loginPath = '/login';
        }

        return [
            'api_token' => $apiToken,
            'auth_header_name' => $authHeaderName,
            'login_path' => $loginPath,
        ];
    }

    public function supports(Device $device): bool
    {
        return $device->os === 'purestorage' && $this->apiConfig !== null;
    }

    public function capabilities(): array
    {
        return ['sensors', 'ports', 'inventory', 'storage', 'transceivers', 'ipv4', 'ports_stats', 'storage_details'];
    }

    /**
     * Fetch detailed controller information
     */
    public function fetchControllers(Device $device): array
    {
        $controllers = [];

        try {
            $data = $this->get('/controllers');
            $items = $data['items'] ?? [];

            foreach ($items as $controller) {
                $controllers[] = [
                    'controller_name' => $controller['name'] ?? 'Unknown',
                    'model' => $controller['model'] ?? null,
                    'status' => $controller['status'] ?? null,
                    'mode' => $controller['mode'] ?? null,
                    'version' => $controller['version'] ?? null,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchControllers failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $controllers;
    }

    /**
     * Fetch detailed volume information with performance metrics
     */
    public function fetchVolumes(Device $device): array
    {
        $volumes = [];

        try {
            // Get volume list and basic info
            $volumeData = $this->get('/volumes');
            $items = $volumeData['items'] ?? [];

            // Get performance data for volumes
            $perfData = $this->get('/volumes/performance');
            $perfItems = $perfData['items'] ?? [];

            // Create a map of performance data by volume name
            $perfMap = [];
            foreach ($perfItems as $perf) {
                $name = $perf['name'] ?? null;
                if ($name) {
                    $perfMap[$name] = $perf;
                }
            }

            foreach ($items as $volume) {
                $name = $volume['name'] ?? 'Unknown';
                $perf = $perfMap[$name] ?? [];

                $volumes[] = [
                    'volume_name' => $name,
                    'volume_id' => $volume['id'] ?? null,
                    'read_bandwidth' => $perf['read_bytes_per_sec'] ?? 0,
                    'write_bandwidth' => $perf['write_bytes_per_sec'] ?? 0,
                    'read_iops' => $perf['reads_per_sec'] ?? 0,
                    'write_iops' => $perf['writes_per_sec'] ?? 0,
                    'read_latency' => $perf['usec_per_read_op'] ?? null,
                    'write_latency' => $perf['usec_per_write_op'] ?? null,
                    'size_bytes' => $volume['provisioned'] ?? 0,
                    'used_bytes' => $volume['space']['total_physical'] ?? 0,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchVolumes failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $volumes;
    }

    /**
     * Fetch connected host information
     */
    public function fetchHosts(Device $device): array
    {
        $hosts = [];

        try {
            $data = $this->get('/hosts');
            $items = $data['items'] ?? [];

            foreach ($items as $host) {
                $name = $host['name'] ?? 'Unknown';

                // Get host connectivity details
                $portStatus = 'unknown';
                $portDetails = [];

                if (isset($host['connection_count'])) {
                    $portStatus = $host['connection_count'] > 0 ? 'connected' : 'offline';
                    $portDetails[] = "{$host['connection_count']}";
                }

                $hosts[] = [
                    'host_name' => $name,
                    'personality' => $host['personality'] ?? null,
                    'host_group' => $host['hgroup'] ?? null,
                    'is_local' => $host['is_local'] ?? false,
                    'port_connectivity_status' => $portStatus,
                    'port_connectivity_details' => !empty($portDetails) ? implode(', ', $portDetails) : null,
                    'iqn' => $host['iqn'][0] ?? null,
                    'wwns' => isset($host['wwn']) ? json_encode($host['wwn']) : null,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchHosts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $hosts;
    }

    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            // Get array information
            $arrayData = $this->get('/arrays');
            $items = $arrayData['items'] ?? [$arrayData];

            foreach ($items as $array) {
                $name = $array['name'] ?? 'array';

                // Capacity sensors
                if (isset($array['capacity'])) {
                    $sensors[] = [
                        'sensor_index'   => 'array-1-total-capacity',
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Total Capacity",
                        'sensor_divisor' => 1,
                        'sensor_multiplier' => 1,
                        'sensor_current' => $array['capacity'] ?? 0,
                        'sensor_limit' => null,
                        'sensor_limit_warn' => null,
                        'sensor_limit_low' => null,
                        'sensor_limit_low_warn' => null,
                        'sensor_alert' => 1,
                        'sensor_custom' => 'No',
                        'entPhysicalIndex' => null,
                        'entPhysicalIndex_measured' => null,
                        'sensor_prev' => null,
                        'user_func' => null,
                        'rrd_type' => 'GAUGE',
                    ];
                }

                // Space usage
                if (isset($array['space'])) {
                    $space = $array['space'];
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Used Space",
                        'sensor_current' => $space['total_physical'] ?? 0,
                        'sensor_limit' => $array['capacity'] ?? null,
                    ];
                }
            }

            // Get array performance
            $perfData = $this->get('/arrays/performance');
            $perfItems = $perfData['items'] ?? [$perfData];

            foreach ($perfItems as $perf) {
                $name = $perf['name'] ?? 'array';

                // Read bandwidth
                if (isset($perf['reads_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Read IOPS",
                        'sensor_current' => $perf['reads_per_sec'],
                    ];
                }

                // Write bandwidth
                if (isset($perf['writes_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Write IOPS",
                        'sensor_current' => $perf['writes_per_sec'],
                    ];
                }

                // Latency
                if (isset($perf['usec_per_read_op'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Read Latency",
                        'sensor_current' => $perf['usec_per_read_op'],
                    ];
                }
            }

            // Get hardware status
            $hwData = $this->get('/hardware');
            $hwItems = $hwData['items'] ?? [];

            foreach ($hwItems as $hw) {
                $name = $hw['name'] ?? 'unknown';
                $type = $hw['type'] ?? 'unknown';

                // Temperature sensors
                if (isset($hw['temperature']) && $hw['temperature'] !== null) {
                    $sensors[] = [
                        'sensor_class' => 'temperature',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Temperature",
                        'sensor_current' => $hw['temperature'],
                    ];
                }

                // Voltage sensors
                if (isset($hw['voltage']) && $hw['voltage'] !== null) {
                    $sensors[] = [
                        'sensor_class' => 'voltage',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Voltage",
                        'sensor_current' => $hw['voltage'],
                    ];
                }

                // Status as state sensor
                if (isset($hw['status'])) {
                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Status",
                        'sensor_current' => $hw['status'] === 'ok' ? 1 : 0,
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchSensors failed', [
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
            $data = $this->get('/network-interfaces');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $interface) {
                $ports[] = [
                    'ifIndex' => $idx + 1,
                    'ifName' => $interface['name'] ?? "port$idx",
                    'ifDescr' => $interface['name'] ?? "port$idx",
                    'ifAlias' => '',
                    'ifType' => 'ethernetCsmacd',
                    'ifOperStatus' => isset($interface['enabled']) && $interface['enabled'] ? 'up' : 'down',
                    'ifAdminStatus' => isset($interface['enabled']) && $interface['enabled'] ? 'up' : 'down',
                    'ifSpeed' => $interface['speed'] ?? 0,
                    'ifMtu' => $interface['mtu'] ?? 1500,
                    'ifPhysAddress' => $interface['hwaddr'] ?? '',
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
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
        $inventory = [];

        try {
            // Get hardware components
            $hwData = $this->get('/hardware');
            $items = $hwData['items'] ?? [];

            foreach ($items as $idx => $hw) {
                $inventory[] = [
                    'entPhysicalIndex' => $idx + 1,
                    'entPhysicalDescr' => $hw['name'] ?? 'Unknown',
                    'entPhysicalClass' => $hw['type'] ?? 'other',
                    'entPhysicalName' => $hw['name'] ?? '',
                    'entPhysicalModelName' => $hw['model'] ?? '',
                    'entPhysicalSerialNum' => $hw['serial'] ?? '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'Pure Storage',
                    'entPhysicalParentRelPos' => -1,
                    'entPhysicalVendorType' => null,
                    'entPhysicalHardwareRev' => $hw['version'] ?? '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => 'true',
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchInventory failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    public function fetchStorage(Device $device): array
    {
        $storage = [];

        try {
            // Get volumes for storage information
            $volumeData = $this->get('/volumes');
            $items = $volumeData['items'] ?? [];

            foreach ($items as $idx => $volume) {
                $name = $volume['name'] ?? "volume$idx";
                $size = $volume['provisioned'] ?? 0;
                $used = $volume['space']['total_physical'] ?? 0;

                $storage[] = [
                    'storage_index' => 'volume-' . $idx,
                    'storage_descr' => $name,
                    'storage_type' => 'purestorage-volume',
                    'storage_size' => $size,
                    'storage_used' => $used,
                    'storage_free' => max(0, $size - $used),
                    'storage_units' => 1,
                    'storage_perc' => $size > 0 ? round(($used / $size) * 100, 2) : 0,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchStorage failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    public function fetchTransceivers(Device $device): array
    {
        $transceivers = [];

        try {
            // Get port details which may include transceiver information
            $portData = $this->get('/ports');
            $items = $portData['items'] ?? [];

            foreach ($items as $idx => $port) {
                if (!empty($port['wwn'])) {
                    // This is a Fibre Channel port
                    $transceivers[] = [
                        'ifIndex' => $idx + 1,
                        'port_id' => null, // Will be resolved by persistor
                        'port_descr_type' => $port['name'] ?? '',
                        'port_descr_descr' => $port['wwn'] ?? '',
                        'port_descr_speed' => '',
                        'port_descr_circuit' => '',
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchTransceivers failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $transceivers;
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        $addresses = [];

        try {
            $data = $this->get('/network-interfaces');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $interface) {
                if (!empty($interface['address'])) {
                    $addresses[] = [
                        'ifIndex' => $idx + 1,
                        'ipv4_address' => $interface['address'],
                        'ipv4_prefixlen' => $interface['netmask'] ?? 24, // Convert mask if needed
                        'context_name' => '',
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchIpv4Addresses failed', [
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
            $perfData = $this->get('/network-interfaces/performance');
            $items = $perfData['items'] ?? [];

            foreach ($items as $idx => $perf) {
                $stats[] = [
                    'ifIndex' => $idx + 1,
                    'ifInOctets' => $perf['received_bytes_per_sec'] ?? 0,
                    'ifOutOctets' => $perf['transmitted_bytes_per_sec'] ?? 0,
                    'ifInErrors' => $perf['received_errors_per_sec'] ?? 0,
                    'ifOutErrors' => $perf['transmitted_errors_per_sec'] ?? 0,
                    'ifInUcastPkts' => $perf['received_packets_per_sec'] ?? 0,
                    'ifOutUcastPkts' => $perf['transmitted_packets_per_sec'] ?? 0,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchPortsStatistics failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    public function isReachable(): bool
    {
        try {
            $this->get('/arrays');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            $data = $this->get('/arrays');
            return [
                'vendor' => 'purestorage',
                'api_version' => '2.x',
                'reachable' => true,
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => 'purestorage',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}