<?php

namespace App\ApiClients\PureStorage;

use App\ApiClients\AuthStrategies\AuthStrategyFactory;
use App\ApiClients\AuthStrategies\AuthContext;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class FlashArrayClient
{
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

        return [
            'api_token' => $this->apiConfig->getValue('api_token') ?? $this->apiConfig->getValue('api_key'),
            'auth_header_name' => $this->apiConfig->getValue('auth_header_name') ?? 'X-Auth-Token',
            'login_path' => $this->apiConfig->getValue('login_path') ?? '/login',
        ];
    }
}