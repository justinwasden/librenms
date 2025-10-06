<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class Api
{
    protected Device $device;
    protected array $poller_options;
    protected Client $client;
    protected array $sessionTokens = [];
    protected DataMatcher $matcher;

    public function __construct(Device $device, array $poller_options = [], Client $client = null)
    {
        $this->device = $device;
        $this->poller_options = $poller_options;
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
				$this->matcher = new DataMatcher();
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
                try {
                    $options = [];

                    if ($connection->disable_ssl_verify) {
                        $options['verify'] = false;
                    }

                    if ($credential = $connection->credential) {
                        $authType = strtolower($credential->authenticationType->name);
                        $params = $credential->params->pluck('value', 'key');

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

                    if ($endpoint->headers) {
                        $options['headers'] = array_merge($options['headers'] ?? [], $endpoint->headers);
                    }
                    if ($endpoint->query_params) {
                        $options['query'] = $endpoint->query_params;
                    }
                    if ($endpoint->body) {
                        $options['json'] = $endpoint->body;
                    }

                    $url = $this->replacePlaceholders($connection->base_url . $endpoint->path, $this->device);
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

                    Log::debug("API Response for endpoint {$endpoint->name}", [
                        'response_sample' => Str::limit(json_encode($body, JSON_PRETTY_PRINT), 1000),
                    ]);

                    if ($body && $endpoint->metric_map) {
                        $this->processApiResponse($endpoint, $body, $connection->id);
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

                // Process all new metrics with DataMatcher
        try {
            $this->matcher->processDeviceMetrics($this->device);
        } catch (\Exception $e) {
            Log::error("DataMatcher failed for device {$this->device->hostname}: " . $e->getMessage());
        }
    }

    protected function processApiResponse(RestApiEndpoint $endpoint, array $data, int $connectionId)
    {
        $resourceType = $endpoint->resource_type ?? 'unknown';

        // Handle different response formats
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (Arr::isList($data)) {
            $items = $data;
        } else {
            $items = [$data];
        }

        Log::debug("Processing " . count($items) . " items for endpoint {$endpoint->name}");

        $currentResourceIds = [];

        foreach ($items as $item) {
            $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id');
            $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name');
            $resourceId = $resourceId ?? $resourceName;

            if ($resourceId) {
                $currentResourceIds[] = $resourceId;
            }

            $this->storeResourceMetrics($endpoint, $item, $resourceType, $connectionId);
        }

        $this->cleanupStaleResources($endpoint, $currentResourceIds);
    }

    protected function normalizeResourceType(?string $resourceType): string
    {
        if (!$resourceType) return 'unknown';

        $type = strtolower(trim($resourceType));
        $mappings = [
            'array' => 'storage',
            'controller' => 'device',
            'host' => 'device',
            'network' => 'port',
            'interface' => 'port',
            'volume' => 'storage',
            'disk' => 'storage',
            'fan' => 'sensor',
            'temperature' => 'sensor',
            'power-supply' => 'sensor',
            'latency' => 'performance',
            'iops' => 'performance',
            'throughput' => 'performance',
            'bandwidth' => 'performance',
        ];

        return $mappings[$type] ?? $type;
    }

    protected function storeResourceMetrics(RestApiEndpoint $endpoint, array $item, string $resourceType, int $connectionId)
    {
        $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id');
        $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name');

        if (!$resourceId && !$resourceName) {
            Log::warning("No resource ID or name found for item in endpoint {$endpoint->name}");
            return;
        }

        $resourceId = $resourceId ?? $resourceName;
        $resourceName = $resourceName ?? $resourceId;

        $collectedAt = Carbon::now();

        $existingMetrics = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where('api_endpoint_id', $endpoint->id)
            ->where('resource_id', $resourceId)
            ->get()
            ->keyBy('metric_name');

        $metricsToInsert = [];
        $metricsToUpdate = [];
        $processedMetricNames = [];

        foreach ($endpoint->metric_map as $metricName => $apiPath) {
            try {
                $value = data_get($item, $apiPath);
                $processedMetricNames[] = $metricName;

                if ($value === null) {
                    continue;
                }

                $isNumeric = is_numeric($value);
                $numericValue = $isNumeric ? (float)$value : null;
                $stringValue = null;

                if (!$isNumeric) {
                    if (is_array($value) || is_object($value)) {
                        $stringValue = json_encode($value);
                    } else {
                        $stringValue = (string)$value;
                    }
                }

                if (isset($existingMetrics[$metricName])) {
                    $existing = $existingMetrics[$metricName];
                    $valueChanged = false;

                    if ($isNumeric) {
                        $valueChanged = abs($existing->value - $numericValue) > 0.0001;
                    } else {
                        $valueChanged = $existing->string_value !== $stringValue;
                    }

                    if ($valueChanged) {
                        $metricsToUpdate[] = [
                            'id' => $existing->id,
                            'value' => $numericValue,
                            'string_value' => $stringValue,
                            'collected_at' => $collectedAt,
                            'updated_at' => $collectedAt,
                        ];

                        Log::debug("Metric {$metricName} changed from " . ($existing->value ?? $existing->string_value) . " to " . ($numericValue ?? $stringValue) . " for resource {$resourceName}");
                    } else {
                        DB::table('device_api_metrics')
                            ->where('id', $existing->id)
                            ->update([
                                'collected_at' => $collectedAt,
                                'updated_at' => $collectedAt,
                            ]);
                    }
                } else {
                    $metricsToInsert[] = [
                        'device_id' => $this->device->device_id,
                        'api_endpoint_id' => $endpoint->id,
                        'api_connection_id' => $connectionId,
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                        'resource_name' => $resourceName,
                        'metric_name' => $metricName,
                        'metric_type' => 'gauge',
                        'value' => $numericValue,
                        'string_value' => $stringValue,
                        'raw_response' => null,
                        'collected_at' => $collectedAt,
                        'created_at' => $collectedAt,
                        'updated_at' => $collectedAt,
                    ];
                    Log::debug("New metric {$metricName} = " . ($numericValue ?? $stringValue) . " for resource {$resourceName}");
                }

            } catch (\Exception $e) {
                Log::error("Error processing metric {$metricName}: " . $e->getMessage());
            }
        }

        // Delete obsolete metrics
        $metricsToDelete = $existingMetrics->keys()->diff($processedMetricNames);
        if ($metricsToDelete->isNotEmpty()) {
            DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('api_endpoint_id', $endpoint->id)
                ->where('resource_id', $resourceId)
                ->whereIn('metric_name', $metricsToDelete->toArray())
                ->delete();
            Log::info("Deleted " . $metricsToDelete->count() . " obsolete metrics for {$resourceType} '{$resourceName}'");
        }

        // Batch insert new metrics
        if (!empty($metricsToInsert)) {
            try {
                DB::table('device_api_metrics')->insert($metricsToInsert);
                Log::info("Inserted " . count($metricsToInsert) . " new metrics for {$resourceType} '{$resourceName}'");
            } catch (\Exception $e) {
                Log::error("Failed to insert metrics for resource {$resourceName}: " . $e->getMessage());
            }
        }

        // Batch update changed metrics
        if (!empty($metricsToUpdate)) {
            try {
                foreach ($metricsToUpdate as $metric) {
                    DB::table('device_api_metrics')
                        ->where('id', $metric['id'])
                        ->update([
                            'value' => $metric['value'],
                            'string_value' => $metric['string_value'],
                            'collected_at' => $metric['collected_at'],
                            'updated_at' => $metric['updated_at'],
                        ]);
                }
                Log::info("Updated " . count($metricsToUpdate) . " changed metrics for {$resourceType} '{$resourceName}'");
            } catch (\Exception $e) {
                Log::error("Failed to update metrics for resource {$resourceName}: " . $e->getMessage());
            }
        }
    }

    protected function cleanupStaleResources(RestApiEndpoint $endpoint, array $currentResourceIds): void
    {
        if (empty($currentResourceIds)) {
            return;
        }

        $existingResourceIds = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where('api_endpoint_id', $endpoint->id)
            ->distinct()
            ->pluck('resource_id')
            ->toArray();

        $staleResourceIds = array_diff($existingResourceIds, $currentResourceIds);

        if (!empty($staleResourceIds)) {
            $deletedCount = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('api_endpoint_id', $endpoint->id)
                ->whereIn('resource_id', $staleResourceIds)
                ->delete();

            Log::info("Removed {$deletedCount} metrics for " . count($staleResourceIds) . " stale resources from endpoint {$endpoint->name}");
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

    private function replacePlaceholders(string $string, Device $device): string
    {
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
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
            $params = $connection->credential->params->pluck('value', 'key');

            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                return null;
            }

            $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');
            $loginUrl = $this->replacePlaceholders($loginUrl, $this->device);

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

    protected function matchDevicePort(string $resourceName)
		{
		    if (empty($resourceName) || !$this->device) {
		        return null;
		    }

		    // Try multiple match types (exact, partial, normalized)
		    return \App\Models\Port::where('device_id', $this->device->device_id)
		        ->where(function ($query) use ($resourceName) {
		            $query->where('ifName', $resourceName)
		                  ->orWhere('ifDescr', $resourceName)
		                  ->orWhere('ifAlias', 'like', "%{$resourceName}%");
		        })
		        ->first();
		}

    protected function matchDeviceSensor(string $resourceName)
		{
		    if (empty($resourceName) || !$this->device) {
		        return null;
		    }

		    return \App\Models\Sensor::where('device_id', $this->device->device_id)
		        ->where(function ($query) use ($resourceName) {
		            $query->where('sensor_descr', $resourceName)
		                  ->orWhere('sensor_oid', $resourceName)
		                  ->orWhere('sensor_index', $resourceName)
		                  ->orWhere('sensor_descr', 'like', "%{$resourceName}%");
		        })
		        ->first();
		}

    protected function matchDeviceStorage(string $resourceName)
		{
		    if (empty($resourceName) || !$this->device) {
		        return null;
		    }

		    return \App\Models\Storage::where('device_id', $this->device->device_id)
		        ->where(function ($query) use ($resourceName) {
		            $query->where('storage_descr', $resourceName)
		                  ->orWhere('storage_descr', 'like', "%{$resourceName}%");
		        })
		        ->first();
		}
}
