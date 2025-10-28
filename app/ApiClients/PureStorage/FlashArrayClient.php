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
        return ['sensors', 'ports', 'inventory'];
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
                        'sensor_class' => 'storage',
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
                        'sensor_class' => 'storage',
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

    public function fetchIpv4Addresses(Device $device): array
    {
        return [];
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