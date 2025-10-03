<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Api
{
    protected Device $device;
    protected array $poller_options;
    protected Client $client;
    protected array $sessionTokens = [];

    public function __construct(Device $device, array $poller_options = [], Client $client = null)
    {
        $this->device = $device;
        $this->poller_options = $poller_options;
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public function poll()
    {
        if (!$this->device->restApiConnections()->exists()) {
            return;
        }

        Log::info("Polling REST APIs for device {$this->device->hostname}");

        $this->device->load('restApiConnections.endpoints', 'restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

        // Initialize global poll state arrays
        $GLOBALS['poll_state']['rest_api']['metrics'] = [];
        $GLOBALS['poll_state']['rest_api']['custom_metrics'] = [];

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

            if ($connection->credential) {
                Log::debug("Connection {$connection->name} using auth type: {$connection->credential->authenticationType->name}");
                if ($sessionToken) {
                    Log::debug("Session token obtained and will be used for connection {$connection->name}");
                } else {
                    Log::debug("No session token obtained for connection {$connection->name}");
                }
            } else {
                Log::debug("Connection {$connection->name} has no credential configured");
            }


            foreach ($connection->endpoints as $endpoint) {
                try {
                    $options = [];

                    if ($connection->disable_ssl_verify) {
                        $options['verify'] = false;
                    }

                    if ($credential = $connection->credential) {
                        $authType = strtolower($credential->authenticationType->name);
                        $params = $credential->params->pluck('value', 'key');

                        Log::debug("Applying authentication type '{$authType}' for endpoint {$endpoint->name}");

                        if ($authType === 'basic auth' && isset($params['username'], $params['password'])) {
                            $options['auth'] = [$params['username'], $params['password']];
                            Log::debug("Applied Basic Auth for endpoint {$endpoint->name}");
                        } elseif ($authType === 'token' && isset($params['token'], $params['header'])) {
                            $scheme = !empty($params['scheme']) ? $params['scheme'] . ' ' : '';
                            $options['headers'][$params['header']] = $scheme . $params['token'];
                            Log::debug("Applied Token auth with header {$params['header']} for endpoint {$endpoint->name}");
                        } elseif ($authType === 'session token' && $sessionToken) {
                            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
                            $options['headers'][$tokenHeader] = $sessionToken;
                            Log::debug("Applied Session Token auth with header {$tokenHeader} for endpoint {$endpoint->name}");
                        } elseif ($authType === 'session token' && !$sessionToken) {
                            Log::warning("Session Token auth type selected but no session token available for endpoint {$endpoint->name}");
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
                    Log::debug("Polling URL: {$url} for device {$this->device->hostname}");

                    $response = $this->client->request($endpoint->method, $url, $options);
                    $statusCode = $response->getStatusCode();

                    if ($statusCode < 200 || $statusCode >= 300) {
                        Log::warning("Non-successful status code {$statusCode} from {$url} for device {$this->device->hostname}");
                        continue;
                    }

                    $bodyContent = $response->getBody()->getContents();

                    $body = json_decode($bodyContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning("Invalid JSON response from {$url} for device {$this->device->hostname}: " . json_last_error_msg());
                        continue;
                    }

                    Log::debug("API Response for endpoint {$endpoint->name}", [
                        'url' => $url,
                        'response_keys' => is_array($body) ? array_keys($body) : 'not an array',
                        'response_sample' => Str::limit(json_encode($body, JSON_PRETTY_PRINT), 2000),
                    ]);

                    if (!is_array($body)) {
                        Log::warning("API response is not an array for endpoint {$endpoint->name}, got: " . gettype($body));
                        continue;
                    }

                    if ($body && $endpoint->metric_map) {
                        $this->mapData($endpoint, $body);
                    }

                    $endpoint->update(['last_polled' => Carbon::now()]);

                    $this->updateRateLimit($connection);

                } catch (RequestException $e) {
                    $message = $e->getMessage();
                    if ($e->hasResponse()) {
                        $responseBody = $e->getResponse()->getBody();
                        $message .= ' | Response: ' . Str::limit($responseBody, 200);
                    }
                    Log::error("Failed to poll REST API endpoint {$endpoint->name} for device {$this->device->hostname}: " . $message);

                    $this->handleFailedEndpoint($endpoint);

                } catch (\Exception $e) {
                    Log::error("An unexpected error occurred while polling endpoint {$endpoint->name} for device {$this->device->hostname}: " . $e->getMessage());
                }
            }
        }

        $this->storeCustomMetrics();
    }


    protected function mapData(RestApiEndpoint $endpoint, array $data)
    {
        Log::debug("Mapping data for endpoint {$endpoint->name}", ['map' => $endpoint->metric_map]);

        foreach ($endpoint->metric_map as $metricName => $apiPath) {
            try {
                $values = data_get($data, $apiPath);

                if ($values === null) {
                    Log::debug("Metric '$metricName' with path '$apiPath' not found in API response for endpoint {$endpoint->name}.");
                    continue;
                }

                if (is_array($values) && Arr::isList($values)) {
                    foreach ($values as $index => $value) {
                        $indexedMetricName = "{$metricName}.{$index}";
                        $this->storeMetric($endpoint, $indexedMetricName, $value);
                    }
                } else {
                    $this->storeMetric($endpoint, $metricName, $values);
                }
            } catch (\Exception $e) {
                Log::error("Error mapping metric {$metricName} for endpoint {$endpoint->name}: " . $e->getMessage());
            }
        }
    }


    /**
     * Determines where to store the metric: core poll state or custom table.
     */
    protected function storeMetric(RestApiEndpoint $endpoint, string $metricName, $value)
    {
        try {
            if (!is_scalar($value) && !is_array($value) && !is_null($value)) {
                Log::warning("Invalid metric value type for {$metricName}: " . gettype($value));
                return;
            }

            $lowerMetricName = Str::lower($metricName);
            $storageValue = is_scalar($value) ? $value : json_encode($value);

            // Ensure value is never NULL if the column is NOT NULL
            if ($storageValue === null) {
                 $storageValue = '';
            }

            // --- 2. TRY TO MATCH EXISTING CORE METRICS ---
            if ($this->isCoreMetric($lowerMetricName)) {
                $GLOBALS['poll_state']['rest_api']['metrics'][$metricName] = $value;
                Log::debug("[REST API STORE] Metric '{$metricName}' (Core Match).");
                return;
            }

            // --- 3. FALLBACK TO CUSTOM TABLE (rest_api_metrics) ---

            $collectedAt = Carbon::now();

            $GLOBALS['poll_state']['rest_api']['custom_metrics'][] = [
                'endpoint_id' => $endpoint->id,
                'metric_name' => $metricName,
                'metric_value' => $storageValue,
                'collected_at' => $collectedAt, // Store Carbon object here, formatted later
            ];

            Log::debug("[REST API STORE] Metric '{$metricName}' (Custom Fallback).");

        } catch (\Exception $e) {
            Log::error("Error in storeMetric {$metricName}: " . $e->getMessage());
        }
    }

    /**
     * Batch inserts all custom metrics collected during the poll run.
     */
    protected function storeCustomMetrics(): void
    {
        if (!empty($GLOBALS['poll_state']['rest_api']['custom_metrics'])) {
            $metricsToInsert = $GLOBALS['poll_state']['rest_api']['custom_metrics'];

            $now = Carbon::now();
            $nowString = $now->toDateTimeString();

            $metricsToInsert = array_map(function ($metric) use ($nowString) {

                // CRITICAL FIX: Explicitly convert the Carbon object to string for raw SQL insert
                if ($metric['collected_at'] instanceof Carbon) {
                    $metric['collected_at'] = $metric['collected_at']->toDateTimeString();
                }

                // Add mandatory created_at and updated_at fields as strings
                $metric['created_at'] = $nowString;
                $metric['updated_at'] = $nowString;

                return $metric;
            }, $metricsToInsert);

            try {
                // EXPLICIT DEBUG LOG: Show the data structure being inserted for troubleshooting
                Log::error("CRITICAL DB DEBUG: Attempting batch insert of " . count($metricsToInsert) . " metrics into rest_api_metrics. Sample:", [
                    'sample_data' => array_slice($metricsToInsert, 0, 5)
                ]);

                // Execute the batch insert with fully serialized data
                \App\Models\RestApiMetric::insert($metricsToInsert);

                Log::info("Successfully inserted " . count($metricsToInsert) . " custom REST API metrics.");
            } catch (\Illuminate\Database\QueryException $e) {
                // Log the exception to the debug log
                Log::error("CRITICAL DB FAILURE IN BATCH INSERT: " . $e->getMessage(), ['metrics_count' => count($metricsToInsert)]);
            } catch (\Exception $e) {
                 Log::error("CRITICAL DB FAILURE (General Exception): " . $e->getMessage(), ['metrics_count' => count($metricsToInsert)]);
            }
        }
    }

    /**
     * Simple helper to check if a metric name typically belongs to a core LibreNMS entity.
     */
    protected function isCoreMetric(string $lowerMetricName): bool
    {
        $coreNames = [
            'hostname', 'version', 'uptime', 'status', 'total_capacity', 'used_capacity',
            'health_status', 'memory_pct', 'purity_version',
        ];

        $corePrefixes = [
            'cpu_', 'mem_', 'storage_', 'array_', 'volume_', 'host_',
            'ifname', 'ifphysaddress', 'ipv4_', 'ip_', 'drive_',
            'reads_per_sec', 'writes_per_sec', 'read_bytes_per_sec', 'write_bytes_per_sec',
            'usec_per_', 'controller_', 'hardware_', 'alert_',
        ];

        if (in_array($lowerMetricName, $coreNames)) {
            return true;
        }

        foreach ($corePrefixes as $prefix) {
            if (Str::startsWith($lowerMetricName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the connection has exceeded its rate limit
     */
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

        if (count($requests) >= $connection->rate_limit) {
            return false;
        }

        return true;
    }

    /**
     * Update rate limit tracking after a successful request
     */
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

    /**
     * Handle failed endpoint polling with exponential backoff tracking
     */
    protected function handleFailedEndpoint(RestApiEndpoint $endpoint): void
    {
        $cacheKey = "rest_api_failures:{$endpoint->id}";
        $failures = Cache::get($cacheKey, 0);
        $failures++;

        Cache::put($cacheKey, $failures, 3600);

        Log::debug("Endpoint {$endpoint->name} has failed {$failures} times");
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

    /**
     * Get or create a session token for session-based authentication
     */
    protected function getSessionToken($connection): ?string
    {
        if (!$connection->credential || strtolower($connection->credential->authenticationType->name) !== 'session token') {
            return null;
        }

        $cacheKey = "rest_api_session_token:{$connection->id}";
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            Log::debug("Using cached session token for connection {$connection->name}");
            return $cachedToken;
        }

        try {
            $params = $connection->credential->params->pluck('value', 'key');

            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                Log::warning("Session token authentication configured but missing required parameters (api_token, login_path) for connection {$connection->name}");
                return null;
            }

            $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');
            $loginUrl = $this->replacePlaceholders($loginUrl, $this->device);

            Log::info("Obtaining session token from {$loginUrl} for connection {$connection->name}");

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
                Log::warning("Login successful but no {$tokenHeader} header found in response for connection {$connection->name}");
                return null;
            }

            Log::info("Successfully obtained session token for connection {$connection->name}");

            $ttl = (int)($params['session_ttl'] ?? 3600);
            Cache::put($cacheKey, $sessionToken, $ttl);

            return $sessionToken;

        } catch (RequestException $e) {
            $message = $e->getMessage();
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody();
                $message .= ' | Response: ' . Str::limit($responseBody, 200);
            }
            Log::error("Failed to obtain session token for connection {$connection->name}: " . $message);
            return null;
        } catch (\Exception $e) {
            Log::error("Unexpected error obtaining session token for connection {$connection->name}: " . $e->getMessage());
            return null;
        }
    }
}