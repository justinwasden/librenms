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

class Api
{
    protected Device $device;
    protected DataMatcher $matcher;
    protected array $poller_options;
    protected Client $client;
    protected array $sessionTokens = [];

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

       try {
           $this->matcher->processDeviceMetrics($this->device);
       } catch (\Exception $e) {
           Log::error("DataMatcher failed for device {$this->device->hostname}: " . $e->getMessage());
   		 }
    }

    protected function processApiResponse(RestApiEndpoint $endpoint, array $data, int $connectionId)
    {
        $resourceType = $this->normalizeResourceType($endpoint->resource_type);

        // Handle PureStorage API response format: { items: [...] }
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (Arr::isList($data)) {
            // Direct array of items
            $items = $data;
        } else {
            // Single item response
            $items = [$data];
        }

        Log::debug("Processing " . count($items) . " items for endpoint {$endpoint->name}");

        // Track resource IDs from current API response
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
		    if (!$resourceType) {
		        return 'unknown';
		    }

		    $type = strtolower(trim($resourceType));

		    // Define common mappings between endpoint resource types and LibreNMS data models
		    $mappings = [
		        'array'           => 'storage',
		        'controller'      => 'device',
		        'host'            => 'device',
		        'network'         => 'port',
		        'interface'       => 'port',
		        'volume'          => 'storage',
		        'disk'            => 'storage',
		        'fan'             => 'sensor',
		        'temperature'     => 'sensor',
		        'power-supply'    => 'sensor',
		        'latency'         => 'performance',
		        'iops'            => 'performance',
		        'throughput'      => 'performance',
		        'bandwidth'       => 'performance',
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

        switch ($resourceType) {
				    case 'port':
				        $resourceId = $this->matchDevicePort($resourceName);
				        break;
				    case 'sensor':
				        $resourceId = $this->matchDeviceSensor($resourceName);
				        break;
				    case 'storage':
				        $resourceId = $this->matchDeviceStorage($resourceName);
				        break;
				}


        $existingMetrics = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where('api_endpoint_id', $endpoint->id)
            ->where('resource_id', $resourceId)
            ->get()
            ->keyBy('metric_name');

        $metricsToInsert = [];
        $metricsToUpdate = [];
        $processedMetricNames = [];

        // Process each metric mapping
        foreach ($endpoint->metric_map as $metricName => $apiPath) {
            try {
                $value = data_get($item, $apiPath);
                $processedMetricNames[] = $metricName;

                if ($value === null) {
                    continue; // Skip null values
                }

                // Determine if value is numeric or string
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

                // Check if metric exists and compare values
                if (isset($existingMetrics[$metricName])) {
                    $existing = $existingMetrics[$metricName];
                    $valueChanged = false;

                    if ($isNumeric) {
                        // Compare numeric values with small tolerance for floating point
                        $valueChanged = abs($existing->value - $numericValue) > 0.0001;
                    } else {
                        // Compare string values
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

                        // Archive the changed metric to history table for trending
                        $this->archiveMetricToHistory($endpoint, $connectionId, $resourceType, $resourceId, $resourceName, $metricName, $numericValue, $stringValue, $collectedAt);

                        Log::debug("Metric {$metricName} changed from " . ($existing->value ?? $existing->string_value) . " to " . ($numericValue ?? $stringValue) . " for resource {$resourceName}");
                    } else {
                        // Value unchanged, just update timestamp
                        DB::table('device_api_metrics')
                            ->where('id', $existing->id)
                            ->update([
                                'collected_at' => $collectedAt,
                                'updated_at' => $collectedAt,
                            ]);
                        Log::debug("Metric {$metricName} unchanged for resource {$resourceName}");
                    }
                } else {
                    // New metric - insert it
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

        // Delete metrics that are no longer in the API response
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

        try {
				    $this->matcher->processDeviceMetrics($this->device);
				} catch (\Exception $e) {
				    Log::error("DataMatcher failed for device {$this->device->hostname}: " . $e->getMessage());
				}
    }

    protected function matchDevicePort(string $name): ?int
		{
		    return DB::table('ports')
		        ->where('device_id', $this->device->device_id)
		        ->where(function ($q) use ($name) {
		            $q->where('ifName', $name)
		              ->orWhere('ifDescr', $name)
		              ->orWhere('ifAlias', $name);
		        })
		        ->value('port_id');
		}

		protected function matchDeviceSensor(string $name): ?int
		{
		    return DB::table('sensors')
		        ->where('device_id', $this->device->device_id)
		        ->where('sensor_descr', 'like', "%{$name}%")
		        ->value('sensor_id');
		}

		protected function matchDeviceStorage(string $name): ?int
		{
		    return DB::table('storage')
		        ->where('device_id', $this->device->device_id)
		        ->where('storage_descr', 'like', "%{$name}%")
		        ->value('storage_id');
		}

    protected function archiveMetricToHistory(
        RestApiEndpoint $endpoint,
        int $connectionId,
        string $resourceType,
        string $resourceId,
        string $resourceName,
        string $metricName,
        ?float $numericValue,
        ?string $stringValue,
        Carbon $collectedAt
    ): void {
        try {
            DB::table('device_api_metrics_history')->insert([
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
                'collected_at' => $collectedAt,
                'created_at' => $collectedAt,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to archive metric to history: " . $e->getMessage());
        }
    }

    protected function cleanupStaleResources(RestApiEndpoint $endpoint, array $currentResourceIds): void
    {
        if (empty($currentResourceIds)) {
            return;
        }

        // Find all resource IDs currently in database for this endpoint
        $existingResourceIds = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where('api_endpoint_id', $endpoint->id)
            ->distinct()
            ->pluck('resource_id')
            ->toArray();

        // Find resources that exist in DB but not in current API response
        $staleResourceIds = array_diff($existingResourceIds, $currentResourceIds);

        if (!empty($staleResourceIds)) {
            $deletedCount = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('api_endpoint_id', $endpoint->id)
                ->whereIn('resource_id', $staleResourceIds)
                ->delete();

            Log::info("Removed {$deletedCount} metrics for " . count($staleResourceIds) . " stale resources from endpoint {$endpoint->name}: " . implode(', ', $staleResourceIds));
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
}
