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
        $values = $this->resolveValues($device); // implement to read and decrypt values as array

        // Strategy key: from template or derived from auth_type
        $strategyKey = $template['strategy_key'] ?? $device->getAttrib('rest_auth_type') ?? 'pure_token_login';
        $strategyOpts = array_merge($template['strategy_options'] ?? [], [
            'base_url'   => $http['base_url'],
            'verify_ssl' => $http['verify_tls'],
            'timeout_ms' => $http['timeout_ms'],
            'proxy'      => $http['proxy'] ?? null,
            'values'     => $values,
        ]);

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

    // Implement for your schema/template storage
    protected function resolveValues(Device $device): array
    {
        // If using device_api_configs: decrypt values array fields before returning
        // If using device attribs (rest_*): return a map compatible with strategies
        return [
            'api_login_header_value' => $device->getAttrib('rest_token') ?: null,
            // Add more fields as needed...
        ];
    }
}