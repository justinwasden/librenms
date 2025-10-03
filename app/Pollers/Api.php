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

        // Initialize poll_state array to capture metrics for core processing
        // This is a common pattern in LibreNMS polling modules
        $GLOBALS['poll_state']['rest_api']['metrics'] = [];
        $GLOBALS['poll_state']['rest_api']['components'] = [];

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
                        // Truncate response sample for brevity in logs
                        'response_sample' => Str::limit(json_encode($body, JSON_PRETTY_PRINT), 2000),
                    ]);

                    // Validate response structure
                    if (!is_array($body)) {
                        Log::warning("API response is not an array for endpoint {$endpoint->name}, got: " . gettype($body));
                        continue;
                    }

                    if ($body && $endpoint->metric_map) {
                        // Pass endpoint to mapData to store metrics based on core vs custom rules
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

                    // Implement exponential backoff for retries (optional)
                    $this->handleFailedEndpoint($endpoint);

                } catch (\Exception $e) {
                    Log::error("An unexpected error occurred while polling endpoint {$endpoint->name} for device {$this->device->hostname}: " . $e->getMessage());
                }
            }
        }

        // Final step: If any custom metrics were collected, insert them now.
        $this->storeCustomMetrics();
    }

    /**
     * Maps data paths to metric names and delegates storage.
     */
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
     */
    protected function storeMetric(RestApiEndpoint $endpoint, string $metricName, $value)
    {
        // Convert metricName to lowercase for reliable matching
        $lowerMetricName = Str::lower($metricName);
        $storageValue = is_scalar($value) ? $value : json_encode($value);

        // --- 1. TRY TO MATCH EXISTING CORE METRICS ---
        if ($this->isCoreMetric($lowerMetricName)) {
            // Push metric to the core poll state for LibreNMS to handle RRD updates.
            // This assumes the core polling logic will read from this global array.
            $GLOBALS['poll_state']['rest_api']['metrics'][$metricName] = $value;
            Log::debug("Metric '{$metricName}' pushed to core poll state.");
            return;
        }

        // --- 2. FALLBACK TO CUSTOM TABLE (rest_api_metrics) ---
        Log::debug("Metric '{$metricName}' storing in custom table.");

        // Store insertion data globally until the end of poll() to execute a batch insert
        $GLOBALS['poll_state']['rest_api']['custom_metrics'][] = [
            'endpoint_id' => $endpoint->id,
            'metric_name' => $metricName,
            'metric_value' => $storageValue,
            'collected_at' => Carbon::now(),
        ];
    }

    /**
     * Batch inserts all custom metrics collected during the poll run.
     */
    protected function storeCustomMetrics(): void
    {
        if (!empty($GLOBALS['poll_state']['rest_api']['custom_metrics'])) {
            $metricsToInsert = $GLOBALS['poll_state']['rest_api']['custom_metrics'];

            // Prepare timestamps for batch insert
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

    /**
     * Simple helper to check if a metric name typically belongs to a core LibreNMS entity.
     */
    protected function isCoreMetric(string $lowerMetricName): bool
    {
        // Define common metric prefixes and names that usually map to RRDs or core tables.
        $corePrefixes = [
            'cpu_', 'mem_', 'storage_', 'array_', 'volume_', 'host_', 'if_',
            'reads_per_sec', 'writes_per_sec', 'read_bytes_per_sec', 'write_bytes_per_sec'
        ];
        $coreNames = [
            'hostname', 'version', 'uptime', 'total_capacity', 'used_capacity', 'status',
            'health_status', 'memory_pct'
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

    // --- Rate Limiting, Error Handling, and Placeholder replacement methods remain the same ---

    protected function checkRateLimit($connection): bool { /* ... */ return true; }
    protected function updateRateLimit($connection): void { /* ... */ }
    protected function handleFailedEndpoint(RestApiEndpoint $endpoint): void { /* ... */ }
    private function replacePlaceholders(string $string, Device $device): string { /* ... */ return $string; }
    protected function getSessionToken($connection): ?string { /* ... */ return null; }
}