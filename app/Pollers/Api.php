<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
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

        $this->device->load('restApiConnections.endpoints', 'restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

        foreach ($this->device->restApiConnections as $connection) {
            if (!$connection->enabled || !$this->checkRateLimit($connection)) {
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
                    $response = $this->client->request($endpoint->method, $url, $options);
                    $body = json_decode($response->getBody()->getContents(), true);

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
        $resourceType = $this->normalizeResourceType($endpoint->resource_type);

        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (Arr::isList($data)) {
            $items = $data;
        } else {
            $items = [$data];
        }

        $currentResourceIds = [];

        foreach ($items as $item) {
            $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id') ?? data_get($item, $endpoint->resource_name_path ?? 'name');
            $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name') ?? $resourceId;

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
        $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id') ?? data_get($item, $endpoint->resource_name_path ?? 'name');
        $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name') ?? $resourceId;

        if (!$resourceId) return;

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

        foreach ($endpoint->metric_map as $metricName => $apiPath) {
            try {
                $value = data_get($item, $apiPath);
                if ($value === null) continue;

                $processedMetricNames[] = $metricName;

                $isNumeric = is_numeric($value);
                $numericValue = $isNumeric ? (float)$value : null;
                $stringValue = !$isNumeric ? (is_array($value) || is_object($value) ? json_encode($value) : (string)$value) : null;

                if (isset($existingMetrics[$metricName])) {
                    $existing = $existingMetrics[$metricName];
                    $valueChanged = $isNumeric ? abs($existing->value - $numericValue) > 0.0001 : $existing->string_value !== $stringValue;

                    if ($valueChanged) {
                        $metricsToUpdate[] = [
                            'id' => $existing->id,
                            'value' => $numericValue,
                            'string_value' => $stringValue,
                            'collected_at' => $collectedAt,
                            'updated_at' => $collectedAt,
                        ];
                    } else {
                        DB::table('device_api_metrics')->where('id', $existing->id)->update([
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
                }
             catch (\Exception $e) {
                Log::error("Error processing metric {$metricName}: " . $e->getMessage());
            }
          }
        }

        // Delete obsolete metrics
        $metricsToDelete = $existingMetrics->keys()->diff($processedMetricNames);
        if ($metricsToDelete->isNotEmpty()) {
            DB::table('device_api_metrics')
				}
			}
}

