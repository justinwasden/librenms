<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric; // Ensure this model is used for custom storage
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
    protected array $sessionTokens = []; // Store session tokens per connection

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

        // Eager load all necessary relationships to avoid N+1 queries
        $this->device->load('restApiConnections.endpoints', 'restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

        // Initialize global poll state arrays for metrics and custom storage
        $GLOBALS['poll_state']['rest_api']['metrics'] = [];
        $GLOBALS['poll_state']['rest_api']['custom_metrics'] = [];

        foreach ($this->device->restApiConnections as $connection) {
            // Skip disabled connections
            if (!$connection->enabled) {
                Log::debug("Connection {$connection->name} is disabled, skipping");
                continue;
            }

            // Check rate limiting for this connection
            if (!$this->checkRateLimit($connection)) {
                Log::info("Rate limit reached for connection {$connection->name}, skipping");
                continue;
            }

            // Attempt to obtain session token if using session-based auth
            $sessionToken = $this->getSessionToken($connection);

            // Debug logging for authentication
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

                    // Configure SSL verification
                    if ($connection->disable_ssl_verify) {
                        $options['verify'] = false;
                    }

                    // Handle Authentication
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
                            // Use the session token obtained from the login endpoint
                            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
                            $options['headers'][$tokenHeader] = $sessionToken;
                            Log::debug("Applied Session Token auth with header {$tokenHeader} for endpoint {$endpoint->name}");
                        } elseif ($authType === 'session token' && !$sessionToken) {
                            Log::warning("Session Token auth type selected but no session token available for endpoint {$endpoint->name}");
                        }
                    }

                    // Add other headers, query params, body from endpoint definition
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

                    // Check for successful response
                    if ($statusCode < 200 || $statusCode >= 300) {
                        Log::warning("Non-successful status code {$statusCode} from {$url} for device {$this->device->hostname}");
                        continue;
                    }

                    $bodyContent = $response->getBody()->getContents();

                    // Validate JSON response
                    $body = json_decode($bodyContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning("Invalid JSON response from {$url} for device {$this->device->hostname}: " . json_last_error_msg());
                        continue;
                    }

                    // Debug: Log the response structure
                    Log::debug("API Response for endpoint {$endpoint->name}", [
                        'url' => $url,
                        'response_keys' => is_array($body) ? array_keys($body) : 'not an array',
                        'response_sample' => Str::limit(json_encode($body, JSON_PRETTY_PRINT), 2000),
                    ]);

                    // Validate response structure
                    if (!is_array($body)) {
                        Log::warning("API response is not an array for endpoint {$endpoint->name}, got: " . gettype($body));
                        continue;
                    }

                    if ($body && $endpoint->metric_map) {
                        $this->mapData($endpoint, $body);
                    }

                    $endpoint->update(['last_polled' => Carbon::now()]);

                    // Update rate limit tracking
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

        // Final step: If any custom metrics were collected, insert them now.
        $this->storeCustomMetrics();
    }

    protected function checkRateLimit($connection): bool
    {
        if (!$connection->rate_limit || $connection->rate_limit <= 0) {
            return true; // No rate limit set
        }

        $cacheKey = "rest_api_rate_limit:{$connection->id}";
        $requests = Cache::get($cacheKey, []);

        // Clean up old requests (outside the rate limit window - assume per minute)
        $windowStart = Carbon::now()->subMinute();
        $requests = array_filter($requests, function ($timestamp) use ($windowStart) {
            return Carbon::parse($timestamp)->isAfter($windowStart);
        });

        // Check if we're at the limit
        if (count($requests) >= $connection->rate_limit) {
            return false;
        }

        return true;
    }

    protected function updateRateLimit($connection): void
    {
        if (!$connection->rate_limit || $connection->rate_limit <= 0) {
            return;
        }

        $cacheKey = "rest_api_rate_limit:{$connection->id}";
        $requests = Cache::get($cacheKey, []);
        $requests[] = Carbon::now()->toDateTimeString();

        // Store for 2 minutes to be safe
        Cache::put($cacheKey, $requests, 120);
    }

    protected function handleFailedEndpoint(RestApiEndpoint $endpoint): void
    {
        $cacheKey = "rest_api_failures:{$endpoint->id}";
        $failures = Cache::get($cacheKey, 0);
        $failures++;

        // Store failure count for 1 hour
        Cache::put($cacheKey, $failures, 3600);

        Log::debug("Endpoint {$endpoint->name} has failed {$failures} times");
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

                // Handle arrays of values (components or multiple metrics)
                if (is_array($values) && Arr::isList($values)) {
                    foreach ($values as $index => $value) {
                        // Use a unique name for indexed metrics, e.g., 'volume.0.name'
                        $indexedMetricName = "{$metricName}.{$index}";
                        $this->storeMetric($endpoint, $indexedMetricName, $value);
                    }
                } else {
                    // Handle single scalar metric or complex object metric
                    $this->storeMetric($endpoint, $metricName, $values);
                }
            } catch (\Exception $e) {
                Log::error("Error mapping metric {$metricName} for endpoint {$endpoint->name}: " . $e->getMessage());
            }
        }
    }

    /**
     * Determines where to store the metric: core poll state or custom table.
     * * @param RestApiEndpoint $endpoint
     * @param string $metricName
     * @param mixed $value
     * @return void
     */

		protected function storeMetric(RestApiEndpoint $endpoint, string $metricName, $value)
    {
        // 1. Basic Validation and preparation
        if (!is_scalar($value) && !is_array($value) && !is_null($value)) {
            Log::warning("Invalid metric value type for {$metricName}: " . gettype($value));
            return;
        }

        $lowerMetricName = Str::lower($metricName);
        $storageValue = is_scalar($value) ? $value : json_encode($value);

        // --- 2. TRY TO MATCH EXISTING CORE METRICS ---
        if ($this->isCoreMetric($lowerMetricName)) {
            // Push metric to the core poll state for LibreNMS to process RRDs/tables.
            // This relies on the core poller using the global $poll_state array.
            $GLOBALS['poll_state']['rest_api']['metrics'][$metricName] = $value;
            Log::debug("Metric '{$metricName}' matched core schema. Pushed to poll state.");
            return;
        }

        // --- 3. FALLBACK TO CUSTOM TABLE (rest_api_metrics) ---
        Log::debug("Metric '{$metricName}' storing in custom table.");

        // Store insertion data globally until the end of poll() to execute a batch insert
        $GLOBALS['poll_state']['rest_api']['custom_metrics'][] = [
            'endpoint_id' => $endpoint->id,
            'metric_name' => $metricName,
            'metric_value' => $storageValue,
            'collected_at' => Carbon::now(),
        ];
    }

		protected function storeCustomMetrics(): void
    {
        if (!empty($GLOBALS['poll_state']['rest_api']['custom_metrics'])) {
            $metricsToInsert = $GLOBALS['poll_state']['rest_api']['custom_metrics'];

            $now = Carbon::now();
            $metricsToInsert = array_map(function ($metric) use ($now) {
                $metric['created_at'] = $now;
                $metric['updated_at'] = $now;
                return $metric;
            }, $metricsToInsert);

            // Execute the batch insert
            RestApiMetric::insert($metricsToInsert);
            Log::info("Successfully inserted " . count($metricsToInsert) . " custom REST API metrics.");
        }
    }

		protected function isCoreMetric(string $lowerMetricName): bool
    {
        // --- EXPANDED CORE METRICS LIST ---
        $coreNames = [
            'hostname', 'version', 'uptime', 'status', 'total_capacity', 'used_capacity',
            'health_status', 'memory_pct', 'purity_version',
        ];

        $corePrefixes = [
            'cpu_', 'mem_', 'storage_', 'array_', 'volume_', 'host_',
            'ifname', 'ifspeed', 'ifphysaddress', 'ipv4_', 'ip_', 'drive_',
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

    private function replacePlaceholders(string $string, Device $device): string
    {
        // Replace basic placeholders
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);

        // Handle getAttrib placeholders with regex
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
        // Only attempt session token auth if the authentication type is "Session Token"
        if (!$connection->credential || strtolower($connection->credential->authenticationType->name) !== 'session token') {
            return null;
        }

        // Check if we already have a cached session token for this connection
        $cacheKey = "rest_api_session_token:{$connection->id}";
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            Log::debug("Using cached session token for connection {$connection->name}");
            return $cachedToken;
        }

        try {
            $params = $connection->credential->params->pluck('value', 'key');

            // Required parameters for session-based auth
            $apiToken = $params['api_token'] ?? $params['token'] ?? null;
            $loginPath = $params['login_path'] ?? null;
            $tokenHeader = $params['token_header'] ?? 'x-auth-token';
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';

            if (!$apiToken || !$loginPath) {
                Log::warning("Session token authentication configured but missing required parameters (api_token, login_path) for connection {$connection->name}");
                return null;
            }

            // Build the login URL
            $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');
            $loginUrl = $this->replacePlaceholders($loginUrl, $this->device);

            Log::info("Obtaining session token from {$loginUrl} for connection {$connection->name}");

            // Prepare login request options
            $loginOptions = [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            // Apply SSL verification setting
            if ($connection->disable_ssl_verify) {
                $loginOptions['verify'] = false;
            }

            // Make the login request (usually POST, but check params for method)
            $loginMethod = strtoupper($params['login_method'] ?? 'POST');
            $response = $this->client->request($loginMethod, $loginUrl, $loginOptions);

            // Extract the session token from response headers
            $sessionToken = null;
            if ($response->hasHeader($tokenHeader)) {
                $sessionToken = $response->getHeader($tokenHeader)[0] ?? null;
            }

            if (!$sessionToken) {
                Log::warning("Login successful but no {$tokenHeader} header found in response for connection {$connection->name}");
                return null;
            }

            Log::info("Successfully obtained session token for connection {$connection->name}");

            // Cache the session token for 1 hour (or use TTL from params)
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
