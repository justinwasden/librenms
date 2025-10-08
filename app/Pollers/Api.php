<?php
/**
 * File: app/Pollers/Api.php
 *
 * Poller entry point used by lnms device:poll.
 */

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\Services\ApiResourceService;
use App\Services\DataMatcher;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Api
{
    protected Device $device;
    protected array $poller_options;
    protected Client $client;
    protected array $sessionTokens = [];
    protected DataMatcher $matcher;
    protected ApiResourceService $service;

    public function __construct(Device $device, array $poller_options = [], Client $client = null)
    {
        $this->device = $device;
        $this->poller_options = $poller_options;
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
        $this->matcher = new DataMatcher();
        $this->service = new ApiResourceService();
    }

    public function poll()
    {
        if (!$this->device->restApiConnections()->exists()) {
            return;
        }

        Log::info("Polling REST APIs for device {$this->device->hostname}");

        $this->device->load('restApiConnections.endpoints', 'restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

        foreach ($this->device->restApiConnections as $connection) {
            if (!$connection->enabled) {
                Log::debug("Connection {$connection->name} is disabled, skipping");
                continue;
            }

            if (!$this->checkRateLimit($connection)) {
                Log::info("Rate limit reached for connection {$connection->name}, skipping");
                continue;
            }

            $sessionToken = $this->getSessionToken($connection);

            foreach ($connection->endpoints as $endpoint) {
                if (!$endpoint->enabled) continue;

                try {
                    $options = $this->buildRequestOptions($connection, $endpoint, $sessionToken);

                    $url = $this->replacePlaceholders(rtrim($connection->base_url, '/') . '/' . ltrim($endpoint->path, '/'));
                    Log::debug("Polling URL: {$url}");

                    $response = $this->client->request($endpoint->method, $url, $options);
                    $statusCode = $response->getStatusCode();

                    if ($statusCode < 200 || $statusCode >= 300) {
                        Log::warning("Non-successful status code {$statusCode} from {$url}");
                        continue;
                    }

                    $bodyContent = $response->getBody()->getContents();
                    $body = json_decode($bodyContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning("Invalid JSON response from {$url}: " . json_last_error_msg());
                        continue;
                    }

                    // convert to items[]
                    $items = [];
                    if (isset($body['items']) && is_array($body['items'])) {
                        $items = $body['items'];
                    } elseif (is_array($body) && Arr::isList($body)) {
                        $items = $body;
                    } else {
                        $items = [$body];
                    }

                    foreach ($items as $item) {
                        $resourceType = strtolower($endpoint->resource_type ?? $endpoint->name ?? 'generic');
                        $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id') ?? data_get($item, 'id') ?? data_get($item, 'name') ?? md5(json_encode($item));
                        $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name') ?? $resourceId;

                        // Normalize & stage based on endpoint path / resource type
                        if (str_contains($endpoint->path, '/ports') || str_contains($endpoint->path, '/network-interfaces')) {
                            $normalized = $this->service->normalizeInterfaceItem($item);
                            $this->service->persistPorts($this->device, [$normalized]);
                            $metrics = $this->extractMetrics($item, 'port');
                            $this->service->stageMetrics($this->device, 'port', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/volumes')) {
                            $normalized = $this->service->normalizeStorageItem($item, $resourceName);
                            $this->service->persistStorage($this->device, [$normalized]);
                            $metrics = $this->extractMetrics($item, 'volume');
                            $this->service->stageMetrics($this->device, 'volume', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/arrays')) {
                            $normalized = $this->service->normalizeStorageItem($item, $resourceName);
                            $this->service->persistStorage($this->device, [$normalized]);
                            $metrics = $this->extractMetrics($item, 'array');
                            $this->service->stageMetrics($this->device, 'array', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/controllers')) {
                            $component = $this->service->normalizeControllerItem($item);
                            $this->service->persistComponents($this->device, [$component]);
                            $metrics = $this->extractMetrics($item, 'controller');
                            $this->service->stageMetrics($this->device, 'controller', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/hosts')) {
                            $normalized = $this->service->normalizeHostItem($item);
                            $this->service->persistStorage($this->device, [$normalized]);
                            $metrics = $this->extractMetrics($item, 'host');
                            $this->service->stageMetrics($this->device, 'host', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/alerts')) {
                            $metrics = $this->extractMetrics($item, 'alert');
                            $this->service->stageMetrics($this->device, 'alert', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } elseif (str_contains($endpoint->path, '/performance')) {
                            // performance endpoints may be at array, volume, or iface level
                            $metrics = $this->extractMetrics($item, 'perf');
                            $this->service->stageMetrics($this->device, 'performance', $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);

                        } else {
                            // generic fallback
                            $metrics = $this->extractMetrics($item, 'generic');
                            $this->service->stageMetrics($this->device, $resourceType, $resourceId, $resourceName, $metrics, $endpoint->id, $connection->id);
                        }
                    }

                    $endpoint->update(['last_polled' => Carbon::now()]);
                    $this->updateRateLimit($connection);
                } catch (RequestException $e) {
                    $message = $e->getMessage();
                    if ($e->hasResponse()) {
                        $message .= ' | Response: ' . Str::limit($e->getResponse()->getBody(), 200);
                    }
                    Log::error("Failed to poll endpoint {$endpoint->name}: " . $message);
                    $this->handleFailedEndpoint($endpoint);
                } catch (\Exception $e) {
                    Log::error("Unexpected error polling endpoint {$endpoint->name}: " . $e->getMessage());
                }
            }
        }

        // After staging all metrics, let DataMatcher handle mapping
        try {
            $this->matcher->processDeviceMetrics($this->device);
        } catch (\Exception $e) {
            Log::error("DataMatcher failed for device {$this->device->hostname}: " . $e->getMessage());
        }
    }

    protected function extractMetrics(array $item, string $prefix = ''): array
    {
        $out = [];
        $this->recursiveExtract($item, $prefix ?: null, $out);
        return $out;
    }

    protected function recursiveExtract($node, $prefix = null, array &$out)
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $name = $prefix ? ($prefix . '_' . $k) : $k;
                if (is_array($v)) {
                    $this->recursiveExtract($v, $name, $out);
                } else {
                    $name = strtolower(str_replace([' ', '-', '.'], '_', $name));
                    $out[$name] = $v;
                }
            }
        } else {
            $out[$prefix ?: 'value'] = $node;
        }
    }

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
        $string = Str::replace('{{ $device->hostname }}', $this->device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $this->device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);
        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $this->device->getAttrib($attribName);
                $fullPlaceholder = $matches[0][$index];
                $string = Str::replace($fullPlaceholder, $attribValue ?? '', $string);
            }
        }
        return $string;
    }

    protected function getSessionToken($connection): ?string
    {
        if (!$connection->credential || strtolower($connection->credential->authenticationType->name) !== 'session token') {
            return null;
        }

        $cacheKey = "rest_api_session_token:{$connection->id}";
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            return $cachedToken;
        }

        try {
            $params = $connection->credential->params->pluck('value', 'key')->toArray();

            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                return null;
            }

            $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');
            $loginUrl = $this->replacePlaceholders($loginUrl);

            $loginOptions = [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($connection->disable_ssl_verify) {
                $loginOptions['verify'] = false;
            }

            $loginMethod = strtoupper($params['login_method'] ?? 'POST');
            $response = $this->client->request($loginMethod, $loginUrl, $loginOptions);

            $sessionToken = null;
            if ($response->hasHeader($tokenHeader)) {
                $sessionToken = $response->getHeader($tokenHeader)[0] ?? null;
            }

            if (!$sessionToken) {
                return null;
            }

            $ttl = (int)($params['session_ttl'] ?? 3600);
            Cache::put($cacheKey, $sessionToken, $ttl);

            return $sessionToken;

        } catch (\Exception $e) {
            Log::error("Failed to obtain session token: " . $e->getMessage());
            return null;
        }
    }

    protected function checkRateLimit($connection): bool
    {
        if (!$connection->rate_limit || $connection->rate_limit <= 0) {
            return true;
        }

        $cacheKey = "rest_api_rate_limit:{$connection->id}";
        $requests = Cache::get($cacheKey, []);

        $windowStart = Carbon::now()->subMinute();
        $requests = array_filter($requests, function ($timestamp) use ($windowStart) {
            return Carbon::parse($timestamp)->isAfter($windowStart);
        });

        return count($requests) < $connection->rate_limit;
    }

    protected function updateRateLimit($connection): void
    {
        if (!$connection->rate_limit || $connection->rate_limit <= 0) {
            return;
        }

        $cacheKey = "rest_api_rate_limit:{$connection->id}";
        $requests = Cache::get($cacheKey, []);
        $requests[] = Carbon::now()->toDateTimeString();

        Cache::put($cacheKey, $requests, 120);
    }

    protected function handleFailedEndpoint(RestApiEndpoint $endpoint): void
    {
        $cacheKey = "rest_api_failures:{$endpoint->id}";
        $failures = Cache::get($cacheKey, 0);
        $failures++;
        Cache::put($cacheKey, $failures, 3600);
    }
}
