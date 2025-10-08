<?php
/**
 * File: app/Discovery/RestApiDiscovery.php
 *
 * Performs discovery by calling the device's configured REST API endpoints
 * (leveraging the same connection definitions used by the poller).
 *
 * This class expects Device model to have restApiConnections relationship.
 */

namespace App\Discovery;

use App\Models\Device;
use App\Services\ApiResourceService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class RestApiDiscovery
{
    protected Device $device;
    protected ApiResourceService $service;
    protected Client $client;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->service = new ApiResourceService();
        $this->client = new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    /**
     * Run full discovery. Returns stats array.
     */
    public function discover(): array
    {
        $stats = [
            'ports' => 0,
            'storage' => 0,
            'sensors' => 0,
            'components' => 0,
            'processors' => 0,
        ];

        if (!$this->device->restApiConnections()->where('enabled', 1)->exists()) {
            Log::info("No REST API connections enabled for {$this->device->hostname}");
            return $stats;
        }

        Log::info("Starting REST API discovery for device {$this->device->hostname}");

        // load connections and endpoints
        $this->device->load('restApiConnections.endpoints', 'restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

        foreach ($this->device->restApiConnections as $connection) {
            if (!$connection->enabled) continue;

            $sessionToken = $this->getSessionToken($connection);

            foreach ($connection->endpoints as $endpoint) {
                if (!$endpoint->enabled) continue;

                $url = rtrim($connection->base_url, '/') . '/' . ltrim($endpoint->path, '/');
                $url = $this->replacePlaceholders($url);

                $options = $this->buildRequestOptions($connection, $endpoint, $sessionToken);

                try {
                    $resp = $this->client->request($endpoint->method, $url, $options);
                    $body = json_decode($resp->getBody()->getContents(), true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning("Invalid JSON during discovery for {$url}: " . json_last_error_msg());
                        continue;
                    }

                    // Flatten items
                    $items = [];
                    if (isset($body['items']) && is_array($body['items'])) {
                        $items = $body['items'];
                    } elseif (is_array($body) && array_values($body) === $body) {
                        $items = $body;
                    } else {
                        $items = [$body];
                    }

                    foreach ($items as $item) {
                        $resourceType = strtolower($endpoint->resource_type ?? $endpoint->name ?? 'unknown');

                        // handle known endpoints using normalization helpers
                        switch (true) {
                            case str_contains($endpoint->path, '/arrays'):
                            case $resourceType === 'array':
                                $normalized = $this->service->normalizeStorageItem($item, Arr::get($item, 'name'));
                                $this->service->persistStorage($this->device, [$normalized]);
                                $stats['storage']++;
                                // stage array-level metrics for DataMatcher
                                $metrics = $this->extractMetricsForDiscovery($item, 'array');
                                $this->service->stageMetrics($this->device, 'array', Arr::get($item, 'id', Arr::get($item, 'name')), Arr::get($item, 'name'), $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/alerts'):
                                // store alerts as sensors/state or into device_api_metrics for mapping
                                $metrics = $this->extractMetricsForDiscovery($item, 'alert');
                                $this->service->stageMetrics($this->device, 'alert', Arr::get($item, 'id', Str::limit(json_encode($item), 64)), Arr::get($item, 'issue', Arr::get($item, 'summary', 'alert')), $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/controllers'):
                                $component = $this->service->normalizeControllerItem($item);
                                $this->service->persistComponents($this->device, [$component]);
                                $stats['components']++;
                                $metrics = $this->extractMetricsForDiscovery($item, 'controller');
                                $this->service->stageMetrics($this->device, 'controller', Arr::get($item, 'name', Arr::get($item, 'id')), Arr::get($item, 'name'), $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/volumes'):
                                $normalized = $this->service->normalizeStorageItem($item, Arr::get($item, 'name'));
                                $this->service->persistStorage($this->device, [$normalized]);
                                $stats['storage']++;
                                $metrics = $this->extractMetricsForDiscovery($item, 'volume');
                                $this->service->stageMetrics($this->device, 'volume', Arr::get($item, 'id', Arr::get($item, 'name')), Arr::get($item, 'name'), $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/ports') || str_contains($endpoint->path, '/network-interfaces'):
                                $normalized = $this->service->normalizeInterfaceItem($item);
                                $this->service->persistPorts($this->device, [$normalized]);
                                $stats['ports']++;
                                $metrics = $this->extractMetricsForDiscovery($item, 'port');
                                $resourceId = Arr::get($item, 'name', Arr::get($item, 'eth.address', Arr::get($item, 'id')));
                                $resourceName = $normalized['ifName'] ?? $resourceId;
                                $this->service->stageMetrics($this->device, 'port', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/hosts'):
                                $normalized = $this->service->normalizeHostItem($item);
                                $this->service->persistStorage($this->device, [$normalized]);
                                $stats['storage']++;
                                $metrics = $this->extractMetricsForDiscovery($item, 'host');
                                $this->service->stageMetrics($this->device, 'host', Arr::get($item, 'name', Arr::get($item, 'id')), Arr::get($item, 'name'), $metrics, $endpoint->id, $connection->id);
                                break;

                            case str_contains($endpoint->path, '/performance') || str_contains($endpoint->path, '/performance-by-array') || str_contains($endpoint->path, '/performance/'):
                                // performance endpoints - stage perf metrics as device_api_metrics
                                $metrics = $this->extractMetricsForDiscovery($item, 'perf');
                                $resId = Arr::get($item, 'id', Arr::get($item, 'name', 'perf'));
                                $resName = Arr::get($item, 'name', $resId);
                                $this->service->stageMetrics($this->device, 'performance', $resId, $resName, $metrics, $endpoint->id, $connection->id);
                                break;

                            default:
                                // Generic: stage flatten metrics for admin review & DataMatcher
                                $metrics = $this->extractMetricsForDiscovery($item, 'generic');
                                $resId = Arr::get($item, 'id', Arr::get($item, 'name', md5(json_encode($item))));
                                $resName = Arr::get($item, 'name', $resId);
                                $this->service->stageMetrics($this->device, $endpoint->resource_type ?? 'generic', $resId, $resName, $metrics, $endpoint->id, $connection->id);
                                break;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error("Discovery request failed for {$url}: " . $e->getMessage());
                    continue;
                }
            }
        }

        Log::info("REST API discovery finished for {$this->device->hostname}", $stats);
        return $stats;
    }

    /**
     * Build request options (headers, auth) like the poller uses.
     */
    protected function buildRequestOptions($connection, $endpoint, $sessionToken = null): array
    {
        $options = [];

        if ($connection->disable_ssl_verify) {
            $options['verify'] = false;
        }

        $options['headers'] = $endpoint->headers ?? [];

        if ($credential = $connection->credential) {
            $authType = strtolower($credential->authenticationType->name);
            $params = $credential->params->pluck('value', 'key')->toArray();

            if ($authType === 'basic auth' && isset($params['username'], $params['password'])) {
                $options['auth'] = [$params['username'], $params['password']];
            } elseif ($authType === 'token' && isset($params['token'], $params['header'])) {
                $scheme = !empty($params['scheme']) ? $params['scheme'] . ' ' : '';
                $options['headers'][$params['header']] = $scheme . $params['token'];
            } elseif ($authType === 'session token' && $sessionToken) {
                $tokenHeader = $params['token_header'] ?? 'x-auth-token';
                $options['headers'][$tokenHeader] = $sessionToken;
            }
        }

        if ($endpoint->query_params) {
            $options['query'] = $endpoint->query_params;
        }
        if ($endpoint->body) {
            $options['json'] = $endpoint->body;
        }

        return $options;
    }

    protected function replacePlaceholders(string $string): string
    {
        $string = str_replace('{{ $device->hostname }}', $this->device->hostname, $string);
        $string = str_replace('{{ $device->ip }}', $this->device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);
        if (!empty($matches[2])) {
            foreach ($matches[2] as $i => $attrib) {
                $val = $this->device->getAttrib($attrib);
                $string = str_replace($matches[0][$i], $val ?? '', $string);
            }
        }
        return $string;
    }

    /**
     * Obtain session token for connections configured as "session token"
     * Caches token in Laravel cache keyed by connection id.
     */
    protected function getSessionToken($connection): ?string
    {
        if (!$connection->credential || strtolower($connection->credential->authenticationType->name) !== 'session token') {
            return null;
        }

        $cacheKey = "rest_api_session_token:{$connection->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) return $cached;

        try {
            $params = $connection->credential->params->pluck('value', 'key')->toArray();
            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) return null;

            $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');
            $loginOptions = ['headers' => [$apiTokenHeader => $apiToken, 'Content-Type' => 'application/json']];

            if ($connection->disable_ssl_verify) $loginOptions['verify'] = false;

            $method = strtoupper($params['login_method'] ?? 'POST');
            $resp = $this->client->request($method, $loginUrl, $loginOptions);
            $sessionToken = $resp->hasHeader($tokenHeader) ? $resp->getHeader($tokenHeader)[0] : null;

            if ($sessionToken) {
                $ttl = (int)($params['session_ttl'] ?? 3600);
                Cache::put($cacheKey, $sessionToken, $ttl);
                return $sessionToken;
            }
        } catch (\Throwable $e) {
            Log::error("Failed obtaining session token for connection {$connection->id}: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Flatten an item into "metricName => value" pairs with a prefix
     * Useful for discovery staging into device_api_metrics.
     */
    protected function extractMetricsForDiscovery(array $item, string $prefix = ''): array
    {
        $flatten = [];
        $this->recursiveExtract($item, $prefix ?: null, $flatten);
        return $flatten;
    }

    protected function recursiveExtract($node, $prefix = null, array &$out)
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $name = $prefix ? ($prefix . '_' . $k) : $k;
                if (is_array($v)) {
                    $this->recursiveExtract($v, $name, $out);
                } else {
                    // sanitize name
                    $name = strtolower(str_replace([' ', '-', '.'], '_', $name));
                    $out[$name] = $v;
                }
            }
        } else {
            $key = $prefix ?: 'value';
            $out[$key] = $node;
        }
    }
}
