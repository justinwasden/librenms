<?php

namespace App\ApiClients\PureStorage;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\AuthStrategies\AuthStrategyFactory;
use App\ApiClients\AuthStrategies\AuthContext;
use App\ApiClients\TestableDevice;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class FlashArrayClient implements DeviceApiClientInterface
{
    public const VENDOR = 'purestorage';
    protected Device|TestableDevice $device;
    protected array $httpBaseOpts;
    protected array $requestOpts = [];
    protected ?AuthContext $authCtx = null;

    public function __construct(Device|TestableDevice $device, array $template = [])
    {
        $this->device = $device;

        $http = DeviceApiSettings::httpOptions($device);
        $values = $this->resolveValues();

        $strategyKey = $template['strategy_key'] ?? 'pure_token_login';
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
        // Read from device attributes, decrypting credentials as needed
        $apiToken = DeviceApiSettings::getCredential($this->device, 'api_credential_api_token')
            ?? DeviceApiSettings::getCredential($this->device, 'api_credential_api_key');
        $authHeaderName = $this->device->getAttrib('api_credential_auth_header_name', 'X-Auth-Token');
        $loginPath = $this->device->getAttrib('api_credential_login_path', '/login');

        return [
            'api_token' => $apiToken,
            'auth_header_name' => $authHeaderName,
            'login_path' => $loginPath,
            'timeout_ms' => (int) $this->device->getAttrib('api_credential_timeout_ms', 5000),
            'proxy' => $this->device->getAttrib('api_credential_proxy'),
        ];
    }

    public function supports(Device|TestableDevice $device): bool
    {
        return $device->os === 'purestorage' && $device->getAttrib('api_base_url') !== null;
    }

    public function capabilities(): array
    {
        return [
            'device_info',      // /arrays + /hardware (serial, version, hardware)
            'sensors',          // /arrays, /arrays/performance, /hardware (temp, voltage, IOPS, latency)
            'ports',            // /network-interfaces (NICs)
            'inventory',        // /hardware (chassis, controllers, drives, fans, PSUs) - includes controller serials
            'storage',          // /volumes (storage volumes)
            'transceivers',     // /ports (FC ports with WWN)
            'ipv4',             // /network-interfaces (IP addresses)
            'ports_stats',      // /network-interfaces/performance (port statistics)
            'alerts',           // /alerts (open alerts)
            'storage_hosts',    // /hosts (connected storage initiators)
            'drives',           // /drives (drive inventory)
            'host_groups',      // /host-groups (host group configuration)
            'protection_groups', // /protection-groups (replication groups)
            'fc_ports',         // /ports (FC/iSCSI/NVMe ports with WWN/IQN/NQN)
            'connections',      // /connections (host to volume mappings)
        ];
    }

    /**
     * Fetch detailed controller information including serial numbers from /hardware
     */
    public function fetchControllers(Device $device): array
    {
        $controllers = [];

        try {
            // Get controller info
            $data = $this->get('/controllers');
            $items = $data['items'] ?? [];

            // Get hardware info for serial numbers
            $hwData = $this->get('/hardware');
            $hwItems = $hwData['items'] ?? [];

            // Build a map of controller serials from hardware
            $serialMap = [];
            foreach ($hwItems as $hw) {
                if (($hw['type'] ?? '') === 'controller') {
                    $serialMap[$hw['name'] ?? ''] = $hw['serial'] ?? null;
                }
            }

            foreach ($items as $controller) {
                $name = $controller['name'] ?? 'Unknown';
                $controllers[] = [
                    'controller_name' => $name,
                    'serial' => $serialMap[$name] ?? null,
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

    public function fetchSensors(Device|TestableDevice $device): array
    {
        $sensors = [];

        try {
            // Get array information
            $arrayData = $this->get('/arrays');
            $items = $arrayData['items'] ?? [$arrayData];

            foreach ($items as $array) {
                $name = $array['name'] ?? 'array';

                // Capacity sensors - convert to appropriate unit (TB or GB)
                if (isset($array['capacity'])) {
                    $capacityBytes = $array['capacity'];
                    [$capacityValue, $capacityUnit] = $this->bytesToHumanUnit($capacityBytes);

                    $sensors[] = [
                        'sensor_index'   => 'array-1-total-capacity',
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Total Capacity ($capacityUnit)",
                        'sensor_divisor' => 1,
                        'sensor_multiplier' => 1,
                        'sensor_current' => $capacityValue,
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

                // Space usage - convert to appropriate unit
                if (isset($array['space'])) {
                    $space = $array['space'];
                    $usedBytes = $space['total_physical'] ?? 0;
                    [$usedValue, $usedUnit] = $this->bytesToHumanUnit($usedBytes);

                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => "$name Used Space ($usedUnit)",
                        'sensor_current' => $usedValue,
                        'sensor_limit' => null,
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
                        'sensor_current' => round($hw['temperature']),
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

    public function fetchPorts(Device|TestableDevice $device): array
    {
        $ports = [];

        try {
            $data = $this->get('/network-interfaces');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $interface) {
                $eth = $interface['eth'] ?? [];
                $fc = $interface['fc'] ?? [];
                $services = $interface['services'] ?? [];

                // Determine interface type and media type
                $interfaceType = $interface['interface_type'] ?? 'eth';
                $ifType = $interfaceType === 'fc' ? 'fibreChannel' : 'ethernetCsmacd';

                // Build service description for alias
                $serviceDesc = !empty($services) ? implode(', ', $services) : '';

                $ports[] = [
                    'ifIndex' => $idx + 1,
                    'ifName' => $interface['name'] ?? "port$idx",
                    'ifDescr' => $interface['name'] ?? "port$idx",
                    'ifAlias' => $serviceDesc,
                    'ifType' => $ifType,
                    'ifOperStatus' => isset($interface['enabled']) && $interface['enabled'] ? 'up' : 'down',
                    'ifAdminStatus' => isset($interface['enabled']) && $interface['enabled'] ? 'up' : 'down',
                    'ifSpeed' => $interface['speed'] ?? 0,
                    'ifMtu' => $eth['mtu'] ?? $interface['mtu'] ?? 1500,
                    'ifPhysAddress' => $eth['mac_address'] ?? '',
                    // Additional data for enrichment
                    'ipv4_address' => $eth['address'] ?? null,
                    'ipv4_netmask' => $eth['netmask'] ?? null,
                    'gateway' => $eth['gateway'] ?? null,
                    'wwn' => $fc['wwn'] ?? null,
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
        $inventory = [];

        try {
            // Get hardware components
            $hwData = $this->get('/hardware');
            $items = $hwData['items'] ?? [];

            // First pass: find chassis items to use as container
            $chassisIndex = null;
            $chassisItems = [];
            $controllerItems = [];
            $otherItems = [];

            foreach ($items as $hw) {
                $type = $hw['type'] ?? '';
                $name = $hw['name'] ?? 'Unknown';

                if ($type === 'chassis') {
                    $chassisItems[] = $hw;
                } elseif ($type === 'controller') {
                    $controllerItems[] = $hw;
                } else {
                    $otherItems[] = $hw;
                }
            }

            $idx = 1;

            // Add chassis first (top-level container)
            foreach ($chassisItems as $hw) {
                $chassisIndex = $idx;
                $inventory[] = [
                    'entPhysicalIndex' => $idx,
                    'entPhysicalDescr' => ($hw['model'] ?? 'Chassis') . ' Chassis',
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => $hw['name'] ?? 'CH0',
                    'entPhysicalModelName' => $hw['model'] ?? '',
                    'entPhysicalSerialNum' => $hw['serial'] ?? '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'Pure Storage',
                    'entPhysicalParentRelPos' => 1,
                    'entPhysicalVendorType' => 'chassis',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => 'true',
                    'entPhysicalAlias' => 'FlashArray Chassis',
                    'entPhysicalAssetID' => '',
                ];
                $idx++;
            }

            // Add controllers (contained in chassis)
            foreach ($controllerItems as $hw) {
                $name = $hw['name'] ?? 'Controller';
                $ctrlNumber = preg_replace('/[^0-9]/', '', $name) ?: '0';

                $inventory[] = [
                    'entPhysicalIndex' => $idx,
                    'entPhysicalDescr' => ($hw['model'] ?? 'Controller') . " (Controller $ctrlNumber)",
                    'entPhysicalClass' => 'module',
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => $hw['model'] ?? '',
                    'entPhysicalSerialNum' => $hw['serial'] ?? '',
                    'entPhysicalContainedIn' => $chassisIndex ?? 0,
                    'entPhysicalMfgName' => 'Pure Storage',
                    'entPhysicalParentRelPos' => (int) $ctrlNumber + 1,
                    'entPhysicalVendorType' => 'controller',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => $hw['version'] ?? '',
                    'entPhysicalIsFRU' => 'true',
                    'entPhysicalAlias' => "Storage Controller $ctrlNumber",
                    'entPhysicalAssetID' => '',
                ];
                $idx++;
            }

            // Add other hardware components (only important ones like fans, PSUs, drives)
            $importantTypes = ['fan', 'power_supply', 'drive_bay', 'nvram', 'ssd', 'eth', 'fc'];
            foreach ($otherItems as $hw) {
                $type = $hw['type'] ?? 'other';
                $name = $hw['name'] ?? 'Unknown';

                // Skip temp sensors, voltage sensors, etc. to avoid clutter
                if (!in_array($type, $importantTypes)) {
                    continue;
                }

                // Map Pure types to LibreNMS entPhysicalClass
                $class = match ($type) {
                    'fan' => 'fan',
                    'power_supply' => 'powerSupply',
                    'drive_bay', 'ssd' => 'container',
                    'nvram' => 'module',
                    'eth', 'fc' => 'port',
                    default => 'other',
                };

                // Determine container (controller or chassis)
                $containedIn = $chassisIndex ?? 0;
                if (preg_match('/^CT(\d+)\./', $name, $matches)) {
                    // Find the controller index
                    $ctrlNum = $matches[1];
                    foreach ($inventory as $inv) {
                        if (preg_match("/CT$ctrlNum\$/", $inv['entPhysicalName'])) {
                            $containedIn = $inv['entPhysicalIndex'];
                            break;
                        }
                    }
                }

                $inventory[] = [
                    'entPhysicalIndex' => $idx,
                    'entPhysicalDescr' => $name,
                    'entPhysicalClass' => $class,
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => $hw['model'] ?? '',
                    'entPhysicalSerialNum' => $hw['serial'] ?? '',
                    'entPhysicalContainedIn' => $containedIn,
                    'entPhysicalMfgName' => 'Pure Storage',
                    'entPhysicalParentRelPos' => -1,
                    'entPhysicalVendorType' => $type,
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => in_array($type, ['fan', 'power_supply', 'ssd']) ? 'true' : 'false',
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];
                $idx++;
            }

            \Log::info("PureStorage fetchInventory: Found " . count($chassisItems) . " chassis, " .
                count($controllerItems) . " controllers, " . count($inventory) . " total items");

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchInventory failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    public function fetchStorage(Device|TestableDevice $device): array
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

    public function fetchTransceivers(Device|TestableDevice $device): array
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

    public function fetchIpv4Addresses(Device|TestableDevice $device): array
    {
        $addresses = [];

        try {
            $data = $this->get('/network-interfaces');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $interface) {
                $eth = $interface['eth'] ?? [];
                $address = $eth['address'] ?? null;

                if (!empty($address)) {
                    // Convert dotted decimal netmask to CIDR prefix length
                    $netmask = $eth['netmask'] ?? '255.255.255.0';
                    $prefixLen = $this->netmaskToPrefixLen($netmask);

                    $addresses[] = [
                        'ifIndex' => $idx + 1,
                        'ifName' => $interface['name'] ?? null,
                        'ipv4_address' => $address,
                        'ipv4_prefixlen' => $prefixLen,
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

    /**
     * Convert dotted decimal netmask to CIDR prefix length
     */
    protected function netmaskToPrefixLen(string $netmask): int
    {
        // Handle cases where netmask might already be an integer
        if (is_numeric($netmask)) {
            return (int) $netmask;
        }

        // Convert dotted decimal (e.g., "255.255.255.0") to prefix length (24)
        $binary = '';
        foreach (explode('.', $netmask) as $octet) {
            $binary .= str_pad(decbin((int) $octet), 8, '0', STR_PAD_LEFT);
        }

        return substr_count($binary, '1');
    }

    /**
     * Fetch port statistics from Pure Storage API.
     *
     * Pure Storage API returns instantaneous rates (bytes_per_sec, packets_per_sec)
     * but LibreNMS expects cumulative counters for RRD DERIVE storage.
     *
     * This method synthesizes counters by:
     * 1. Reading current counter values from the database
     * 2. Adding (rate * poll_interval) to get new counter values
     * 3. Returning synthetic counters that RRD can use with DERIVE
     */
    public function fetchPortsStatistics(Device|TestableDevice $device): array
    {
        $stats = [];
        $defaultPollInterval = 300; // 5 minutes default

        try {
            // Get current port data from database to read existing counters
            $existingPorts = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->get(['port_id', 'ifIndex', 'ifName', 'ifInOctets', 'ifOutOctets', 'ifInErrors', 'ifOutErrors', 'ifInUcastPkts', 'ifOutUcastPkts', 'poll_time'])
                ->keyBy('ifName');

            // Calculate poll interval from the last poll time
            $currentTime = time();
            $pollInterval = $defaultPollInterval;

            // Try to get a more accurate poll interval from an existing port
            foreach ($existingPorts as $port) {
                if ($port->poll_time && $port->poll_time > 0) {
                    $timeDiff = $currentTime - $port->poll_time;
                    // Use the time difference if it's reasonable (between 1 minute and 15 minutes)
                    if ($timeDiff > 60 && $timeDiff < 900) {
                        $pollInterval = $timeDiff;
                    }
                    break;
                }
            }

            // First, get the interface list to build a name-to-index map
            $ifaceData = $this->get('/network-interfaces');
            $ifaceItems = $ifaceData['items'] ?? [];
            $nameToIndex = [];
            foreach ($ifaceItems as $idx => $iface) {
                $name = $iface['name'] ?? '';
                if ($name) {
                    $nameToIndex[$name] = $idx + 1;
                }
            }

            // Get performance data (rates)
            $perfData = $this->get('/network-interfaces/performance');
            $items = $perfData['items'] ?? [];

            foreach ($items as $perf) {
                $name = $perf['name'] ?? '';
                $ifIndex = $nameToIndex[$name] ?? null;

                if (!$ifIndex) {
                    continue;
                }

                $eth = $perf['eth'] ?? [];
                $fc = $perf['fc'] ?? [];

                // Extract rates (bytes/packets per second) - handle null values
                $inBytesPerSec = $eth['received_bytes_per_sec'] ?? $fc['received_bytes_per_sec'] ?? 0;
                $outBytesPerSec = $eth['transmitted_bytes_per_sec'] ?? $fc['transmitted_bytes_per_sec'] ?? 0;
                $inErrorsPerSec = $eth['total_errors_per_sec'] ?? $fc['total_errors_per_sec'] ?? 0;
                $outErrorsPerSec = 0;  // Not separately tracked
                $inPktsPerSec = $eth['received_packets_per_sec'] ?? $fc['received_frames_per_sec'] ?? 0;
                $outPktsPerSec = $eth['transmitted_packets_per_sec'] ?? $fc['transmitted_frames_per_sec'] ?? 0;

                // Handle null values from API
                $inBytesPerSec = $inBytesPerSec ?? 0;
                $outBytesPerSec = $outBytesPerSec ?? 0;
                $inErrorsPerSec = $inErrorsPerSec ?? 0;
                $inPktsPerSec = $inPktsPerSec ?? 0;
                $outPktsPerSec = $outPktsPerSec ?? 0;

                // Calculate bytes/packets transferred during this poll interval
                $inBytesThisPoll = (int) ($inBytesPerSec * $pollInterval);
                $outBytesThisPoll = (int) ($outBytesPerSec * $pollInterval);
                $inErrorsThisPoll = (int) ($inErrorsPerSec * $pollInterval);
                $outErrorsThisPoll = (int) ($outErrorsPerSec * $pollInterval);
                $inPktsThisPoll = (int) ($inPktsPerSec * $pollInterval);
                $outPktsThisPoll = (int) ($outPktsPerSec * $pollInterval);

                // Get existing counters from database
                $existingPort = $existingPorts->get($name);
                if ($existingPort) {
                    // Add to existing counters to create synthetic cumulative counters
                    $newInOctets = ($existingPort->ifInOctets ?? 0) + $inBytesThisPoll;
                    $newOutOctets = ($existingPort->ifOutOctets ?? 0) + $outBytesThisPoll;
                    $newInErrors = ($existingPort->ifInErrors ?? 0) + $inErrorsThisPoll;
                    $newOutErrors = ($existingPort->ifOutErrors ?? 0) + $outErrorsThisPoll;
                    $newInPkts = ($existingPort->ifInUcastPkts ?? 0) + $inPktsThisPoll;
                    $newOutPkts = ($existingPort->ifOutUcastPkts ?? 0) + $outPktsThisPoll;
                } else {
                    // No existing port - start counters from this poll's data
                    $newInOctets = $inBytesThisPoll;
                    $newOutOctets = $outBytesThisPoll;
                    $newInErrors = $inErrorsThisPoll;
                    $newOutErrors = $outErrorsThisPoll;
                    $newInPkts = $inPktsThisPoll;
                    $newOutPkts = $outPktsThisPoll;
                }

                // Prevent counter overflow - wrap at 64-bit unsigned max
                $maxCounter = PHP_INT_MAX;
                $newInOctets = $newInOctets % $maxCounter;
                $newOutOctets = $newOutOctets % $maxCounter;
                $newInErrors = $newInErrors % $maxCounter;
                $newOutErrors = $newOutErrors % $maxCounter;
                $newInPkts = $newInPkts % $maxCounter;
                $newOutPkts = $newOutPkts % $maxCounter;

                $stats[] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $name,
                    'ifInOctets' => $newInOctets,
                    'ifOutOctets' => $newOutOctets,
                    'ifInErrors' => $newInErrors,
                    'ifOutErrors' => $newOutErrors,
                    'ifInUcastPkts' => $newInPkts,
                    'ifOutUcastPkts' => $newOutPkts,
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

    public function fetchVms(Device|TestableDevice $device): array
    {
        // PureStorage arrays do not host virtual machines
        return [];
    }

    /**
     * Fetch device info for serial/version/hardware updates
     */
    public function fetchDeviceInfo(Device|TestableDevice $device): array
    {
        try {
            // Get array info for version
            $arrayData = $this->get('/arrays');
            $array = $arrayData['items'][0] ?? $arrayData;
            $version = $array['version'] ?? null;
            $arrayName = $array['name'] ?? null;

            // Get controller info for model
            $ctrlData = $this->get('/controllers');
            $ctrlItems = $ctrlData['items'] ?? [];
            $controllerModel = null;
            foreach ($ctrlItems as $ctrl) {
                if (!empty($ctrl['model'])) {
                    $controllerModel = $ctrl['model'];
                    break; // Use first controller's model
                }
            }

            // Get hardware info for chassis serial
            $hwData = $this->get('/hardware');
            $items = $hwData['items'] ?? [];

            $chassisSerial = null;
            $controllerSerials = [];

            foreach ($items as $hw) {
                $type = $hw['type'] ?? '';
                $name = $hw['name'] ?? '';

                if ($type === 'chassis') {
                    $chassisSerial = $hw['serial'] ?? null;
                } elseif ($type === 'controller') {
                    $controllerSerials[$name] = $hw['serial'] ?? null;
                }
            }

            // Use just the controller model for hardware field
            $hardware = $controllerModel ?: 'FlashArray';

            return [
                'serial' => $chassisSerial,
                'version' => $version,
                'hardware' => $hardware,
                'controller_serials' => $controllerSerials,
            ];
        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchDeviceInfo failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch alerts (open alerts only)
     */
    public function fetchAlerts(Device|TestableDevice $device): array
    {
        $alerts = [];

        try {
            $data = $this->get('/alerts');
            $items = $data['items'] ?? [];

            foreach ($items as $alert) {
                // Only include open (non-closed) alerts
                if (!empty($alert['closed'])) {
                    continue;
                }

                $alerts[] = [
                    'alert_id' => $alert['id'] ?? null,
                    'alert_name' => $alert['name'] ?? 'Unknown',
                    'alert_severity' => $this->mapAlertSeverity($alert['severity'] ?? 'info'),
                    'alert_message' => $alert['summary'] ?? $alert['description'] ?? '',
                    'alert_timestamp' => $alert['opened'] ?? null,
                    'alert_category' => $alert['category'] ?? 'storage',
                    'alert_component' => $alert['component_name'] ?? $alert['component_type'] ?? null,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchAlerts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $alerts;
    }

    /**
     * Map Pure Storage alert severity to LibreNMS severity
     */
    protected function mapAlertSeverity(string $severity): int
    {
        return match (strtolower($severity)) {
            'critical' => 1,
            'warning' => 2,
            'info' => 3,
            default => 4,
        };
    }

    /**
     * Fetch connected storage hosts (initiators)
     */
    public function fetchStorageHosts(Device|TestableDevice $device): array
    {
        $hosts = [];

        try {
            $data = $this->get('/hosts');
            $items = $data['items'] ?? [];

            foreach ($items as $host) {
                $name = $host['name'] ?? 'Unknown';

                // Get WWNs and IQNs
                $wwns = isset($host['wwn']) && is_array($host['wwn']) ? $host['wwn'] : [];
                $iqns = isset($host['iqn']) && is_array($host['iqn']) ? $host['iqn'] : [];

                $hosts[] = [
                    'host_name' => $name,
                    'personality' => $host['personality'] ?? null,
                    'host_group' => $host['hgroup'] ?? null,
                    'is_local' => $host['is_local'] ?? false,
                    'wwns' => $wwns,
                    'iqns' => $iqns,
                    'connection_count' => $host['connection_count'] ?? 0,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchStorageHosts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $hosts;
    }

    /**
     * Fetch drive inventory
     */
    public function fetchDrives(Device|TestableDevice $device): array
    {
        $drives = [];

        try {
            $data = $this->get('/drives');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $drive) {
                $drives[] = [
                    'drive_index' => $idx + 1,
                    'drive_name' => $drive['name'] ?? "drive$idx",
                    'drive_type' => $drive['type'] ?? 'ssd',
                    'drive_status' => $drive['status'] ?? 'unknown',
                    'drive_capacity' => $drive['capacity'] ?? 0,
                    'drive_protocol' => $drive['protocol'] ?? null,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchDrives failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $drives;
    }

    /**
     * Fetch host groups
     */
    public function fetchHostGroups(Device|TestableDevice $device): array
    {
        $groups = [];

        try {
            $data = $this->get('/host-groups');
            $items = $data['items'] ?? [];

            foreach ($items as $group) {
                $groups[] = [
                    'group_name' => $group['name'] ?? 'Unknown',
                    'host_count' => $group['host_count'] ?? 0,
                    'is_local' => $group['is_local'] ?? true,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchHostGroups failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $groups;
    }

    /**
     * Fetch protection groups (replication)
     */
    public function fetchProtectionGroups(Device|TestableDevice $device): array
    {
        $groups = [];

        try {
            $data = $this->get('/protection-groups');
            $items = $data['items'] ?? [];

            foreach ($items as $pg) {
                $groups[] = [
                    'pg_name' => $pg['name'] ?? 'Unknown',
                    'source_retention' => $pg['source_retention'] ?? null,
                    'target_retention' => $pg['target_retention'] ?? null,
                    'is_local' => $pg['is_local'] ?? true,
                    'replication_enabled' => isset($pg['replication_schedule']) ? true : false,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchProtectionGroups failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $groups;
    }

    /**
     * Fetch FC ports (for transceivers)
     */
    public function fetchFcPorts(Device|TestableDevice $device): array
    {
        $ports = [];

        try {
            $data = $this->get('/ports');
            $items = $data['items'] ?? [];

            foreach ($items as $idx => $port) {
                $ports[] = [
                    'port_index' => $idx + 1,
                    'port_name' => $port['name'] ?? "fc$idx",
                    'wwn' => $port['wwn'] ?? null,
                    'iqn' => $port['iqn'] ?? null,
                    'nqn' => $port['nqn'] ?? null,
                    'failover' => $port['failover'] ?? null,
                    'portal' => $port['portal'] ?? null,
                ];
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchFcPorts failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Fetch connections (host to volume mappings)
     */
    public function fetchConnections(Device|TestableDevice $device): array
    {
        $connections = [];

        try {
            $data = $this->get('/connections');
            $items = $data['items'] ?? [];

            foreach ($items as $conn) {
                $host = $conn['host']['name'] ?? null;
                $volume = $conn['volume']['name'] ?? null;

                if ($host && $volume) {
                    $connections[] = [
                        'host_name' => $host,
                        'volume_name' => $volume,
                        'lun' => $conn['lun'] ?? null,
                    ];
                }
            }

        } catch (\Exception $e) {
            \Log::warning('PureStorage fetchConnections failed', [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $connections;
    }

    /**
     * Convert bytes to human-readable unit (TB or GB)
     * Returns [value, unit] where value is rounded to 2 decimals
     * Uses GB if value in TB would be < 1
     */
    protected function bytesToHumanUnit(int $bytes): array
    {
        $tb = $bytes / (1024 * 1024 * 1024 * 1024);

        if ($tb >= 1) {
            return [round($tb, 2), 'TB'];
        }

        $gb = $bytes / (1024 * 1024 * 1024);
        return [round($gb, 2), 'GB'];
    }
}