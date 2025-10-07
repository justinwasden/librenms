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

    private function determineEntPhysicalClass(string $type): string
		{
		    $type = strtolower($type);
		    switch ($type) {
		        case 'controller':
		        case 'ct':              return 'cpu';
		        case 'power_supply':
		        case 'pwr':             return 'powerSupply';
		        case 'fan':
		        case 'cooling':         return 'fan';
		        case 'chassis':         return 'chassis';
		        case 'drive_bay':
		        case 'nvram_bay':
		        case 'ssd':             return 'disk';
		        case 'eth_port':        return 'port';
		        case 'temp_sensor':     return 'sensor';
		        default:                return 'other';
		    }
		}

		private function generateUniqueEntPhysicalIndex(int $device_id, string $resource_id, array $item_data = []): int
		{
		    $unique_id_part = $item_data['serial'] ?? $item_data['uuid'] ?? $resource_id;
		    $component_type = $item_data['type'] ?? 'unknown_type';

		    $stable_seed = $unique_id_part . '_' . $component_type . '_' . crc32(json_encode($item_data));
		    $hash_value = crc32($stable_seed);

		    // Limit index to MySQL's signed INT range (1 to 2147483647).
		    $generated_index = abs($hash_value);
		    $current_index = ($generated_index % 2147483646) + 1;

		    // Safety check against collisions
		    $attempt = 0;
		    while (DB::table('entPhysical')
		        ->where('device_id', $device_id)
		        ->where('entPhysicalIndex', $current_index)
		        ->exists()) {

		        $attempt++;
		        $current_index++;

		        if ($attempt > 100 || $current_index > 2147483647) {
		            // Log an error and stop execution if unable to find a unique index
		            Log::critical("Failed to generate unique entPhysicalIndex for device {$device_id}");
		            throw new \Exception("Entity index generation failed.");
		        }
		    }

		    return $current_index;
		}

    protected function processApiResponse(RestApiEndpoint $endpoint, array $data, int $connectionId)
    {
        $resourceType = $this->normalizeResourceType($endpoint->resource_type ?? 'unknown');

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
            // NOTE: The previous logic contained in DataMatcher::storeResourceMetrics is moved here
            // to ensure entity creation happens before metric processing.
            $this->storeResourceMetrics($endpoint, $item, $resourceType, $connectionId);

            $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id');
            $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name');
            $resourceId = $resourceId ?? $resourceName;

            if ($resourceId) {
                $currentResourceIds[] = $resourceId;
            }
        }

        $this->cleanupStaleResources($endpoint, $currentResourceIds);
    }

	protected function normalizeResourceType(?string $resourceType): string
{
    if (!$resourceType) return 'unknown';

    $type = strtolower(trim($resourceType));

    if (str_contains($type, 'network') || str_contains($type, 'interface') || $type === 'port') {
        return 'port';
    }

    if (str_contains($type, 'drive') || str_contains($type, 'storage') || $type === 'volume') {
        return 'storage';
    }

    // Default mappings for other components for entity creation
    if (str_contains($type, 'controller')) {
        return 'device'; // This maps to the 'device' case in storeResourceMetrics
    }


		    // Valid enum mappings
		    $validMappings = [
		        'controller'     => 'device',
		        'host'           => 'device',
		        'fan'            => 'fanspeed',
		        'temperature'    => 'temperature',
		        'power-supply'   => 'power',
		        'latency'        => 'delay',
		        'iops'           => 'count',
		        'throughput'     => 'count',
		        'bandwidth'      => 'count',
		    ];

		    return $validMappings[$type] ?? 'state'; // fallback to 'state'
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

        // --- 1. ENTITY CREATION/UPDATE ---

		    $itemDataForEntity = Arr::isList($item) ? ['name' => $resourceName, 'id' => $resourceId] : $item;
		    $itemDataForEntity['type'] = data_get($item, 'type') ?? $endpoint->resource_type;
		    $itemDataForEntity['serial'] = data_get($item, 'serial') ?? null;
		    $itemDataForEntity['model'] = data_get($item, 'model') ?? null;

		    if ($resourceType === 'port') {
				    // Need to extract deeply nested port/eth data for creation
				    $ethData = data_get($item, 'eth') ?? [];
				    $portDataForEntity = array_merge($itemDataForEntity, $item);
				    $portDataForEntity['eth'] = $ethData;
				    // NOTE: We don't need to check for 'interface' anymore if normalizeResourceType handles the alias
				    $this->storePortData($this->device, $portDataForEntity);
				}
		    } elseif ($resourceType === 'storage' || $itemDataForEntity['type'] === 'drive_bay' || $itemDataForEntity['type'] === 'ssd') {
		        // Drives/Volumes should be created in the storage table
		        $this->storeDriveStorageData($this->device, $itemDataForEntity);
		    } elseif (in_array($resourceType, ['device', 'sensor', 'processor']) || $itemDataForEntity['type'] === 'controller' || $itemDataForEntity['type'] === 'chassis') {
		        // Controllers, Fans, Temp Sensors, etc., go to 'component'
		        $this->storeHardwareComponentData($this->device, $itemDataForEntity);
		    }

        // --- 2. METRIC STAGING (device_api_metrics) ---

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

		protected function storeHardwareComponentData($device, $componentData)
		{
		    $name = $componentData['name'] ?? 'unknown';
		    $status_str = strtolower($componentData['status'] ?? 'ok');

		    // Convert string status to tinyint status (1 = ok, 0 = down/critical/unknown)
		    $status_int = ($status_str === 'ok' || $status_str === 'up') ? 1 : 0;

		    // The table name is 'component' (singular)
		    DB::table('component')->updateOrInsert(
		        ['device_id' => $device->device_id, 'label' => $name],
		        [
		            'type' => $componentData['type'] ?? 'controller',
		            'status' => $status_int,
		        ]
		    );

		    Log::info("Updated component {$name} for device {$device->hostname}");
		}

		protected function storePortData($device, $portData)
		{
		    if (!isset($portData['name'])) {
		        Log::warning("Missing port name for device {$device->hostname}");
		        return;
		    }

		    $ifName = $portData['name'];
		    $ifDescr = $portData['description'] ?? $ifName;
		    $ifIndex = $portData['index'] ?? crc32($ifName); // fallback unique index

		    // Find or create port entry
		    $port = DB::table('ports')
		        ->where('device_id', $device->device_id)
		        ->where(function ($query) use ($ifIndex, $ifName) {
		            $query->where('ifIndex', $ifIndex)->orWhere('ifName', $ifName);
		        })
		        ->first();

		    if (!$port) {
		        $portId = DB::table('ports')->insertGetId([
		            'device_id' => $device->device_id,
		            'ifIndex' => $ifIndex,
		            'ifName' => $ifName,
		            'ifDescr' => $ifDescr,
		            'ifType' => $portData['type'] ?? 'ethernetCsmacd',
		            'ifSpeed' => $portData['speed'] ?? 0,
		            'ifOperStatus' => $portData['status'] ?? 'up',
		            'ifAdminStatus' => 'up',
		            'ifAlias' => $portData['alias'] ?? null,
		            'ifLastChange' => now()->timestamp, // LibreNMS stores ifLastChange as a Unix timestamp
		        ]);
		    } else {
		        $portId = $port->port_id;
		        DB::table('ports')->where('port_id', $portId)->update([
		            'ifSpeed' => $portData['speed'] ?? $port->ifSpeed,
		            'ifOperStatus' => $portData['status'] ?? $port->ifOperStatus,
		            'ifLastChange' => now()->timestamp, // LibreNMS stores ifLastChange as a Unix timestamp
		        ]);
		    }

		    // Update performance counters directly in the 'ports' table based on your schema.
		    // The original logic was incorrectly targeting 'ports_statistics'.
		    DB::table('ports')->where('port_id', $portId)->update([
		        'ifInOctets' => $portData['rx_bytes'] ?? 0,
		        'ifOutOctets' => $portData['tx_bytes'] ?? 0,
		        'ifInUcastPkts' => $portData['rx_packets'] ?? 0,
		        'ifOutUcastPkts' => $portData['tx_packets'] ?? 0,
		        'poll_time' => time(),
		    ]);

	        // NOTE: If you still need to populate ports_statistics, you must get the raw values from $portData
	        // and insert them into ports_statistics, excluding the fields already handled above.

		    Log::info("Updated port {$ifName} metrics for device {$device->hostname}");
		}

		protected function storeDriveStorageData($device, $storageData)
		{
		    if (!isset($storageData['name'])) {
		        Log::warning("Missing storage name for device {$device->hostname}");
		        return;
		    }

		    $descr = $storageData['name'];
		    $size = $storageData['size'] ?? 0;
		    $used = $storageData['used'] ?? 0;

		    DB::table('storage')->updateOrInsert(
		        ['device_id' => $device->device_id, 'storage_descr' => $descr],
		        [
		            'storage_type' => $storageData['type'] ?? 'purestorage',
		            'storage_size' => $size,
		            'storage_used' => $used,
		            'storage_free' => max(0, $size - $used),
		            'storage_perc' => $size > 0 ? round(($used / $size) * 100, 0) : 0, // Round to integer for storage_perc
		            // 'updated_at' is not a native column in the storage table schema, so we omit it
		        ]
		    );

		    Log::info("Updated storage {$descr} metrics for device {$device->hostname}");
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


}