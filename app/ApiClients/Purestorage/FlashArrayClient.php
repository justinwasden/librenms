<?php

namespace App\ApiClients\PureStorage;

use App\ApiClients\AuthStrategies\AuthStrategyFactory;
use App\ApiClients\AuthStrategies\AuthContext;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class FlashArrayClient
{
    protected Device $device;
    protected array $httpBaseOpts;
    protected array $requestOpts = [];
    protected ?AuthContext $authCtx = null;

    public function __construct(Device $device, array $template = [])
    {
        $this->device = $device;

        // Read HTTP options and decrypted values (from device_api_configs or device attribs)
        $http = DeviceApiSettings::httpOptions($device);
        $values = $this->resolveValues($device);

        // Strategy key: from template or derived from auth_type
        $strategyKey = $template['strategy_key'] ?? $device->getAttrib('rest_auth_type') ?? 'pure_token_login';
        $strategyOpts = array_merge($template['strategy_options'] ?? [], [
            'base_url'   => $http['base_url'],
            'verify_ssl' => $http['verify_tls'],
            'timeout_ms' => $http['timeout_ms'],
            'proxy'      => $http['proxy'] ?? null,
            'values'     => $values,
        ]);

        // Map schema values to Pure strategy expected keys when using Pure login strategy
        if (in_array($strategyKey, ['pure_token_login', 'purestorage_api_token_login'], true)) {
            $strategyOpts['login_url'] = $strategyOpts['login_url'] ?? ($http['base_url'] . '/login');
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

        // Build request options applied to all calls
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

        // Apply cookies if present
        if (!empty($this->requestOpts['_cookies'])) {
            $host = parse_url($this->httpBaseOpts['base_uri'] ?? '', PHP_URL_HOST) ?: '';
            $req = $req->withCookies($this->requestOpts['_cookies'], $host);
        }

        return $req;
    }

    // Example endpoints
    public function getArray(): array
    {
        $resp = $this->client()->get('array');
        return $resp->json() ?: [];
    }

    public function getArrayPerformance(): array
    {
        $resp = $this->client()->get('array/performance');
        return $resp->json() ?: [];
    }

    protected function resolveValues(Device $device): array
    {
        // Return a map compatible with strategies and schemas
        // If using device_api_configs, decrypt fields and return them here.
        // For attribs-based config:
        return [
            // Canonical Pure login schema fields
            'api_token' => $device->getAttrib('rest_token') ?: null,
            'auth_header_name' => 'X-Auth-Token',
            'login_path' => '/login',
        ];
    }
}