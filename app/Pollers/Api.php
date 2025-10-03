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
use Illuminate\Support\Facades\DB; // <-- REQUIRED FACADE
use Illuminate\Support\Str;

class Api
{
    protected Device $device;
    protected array $poller_options;
    protected Client $client;
    protected array $sessionTokens = [];
// ... (rest of class properties) ...

    public function __construct(Device $device, array $poller_options = [], Client $client = null)
    {
        $this->device = $device;
        $this->poller_options = $poller_options;
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public function poll()
    {
        // ... (poll execution logic remains the same) ...

        $GLOBALS['poll_state']['rest_api']['metrics'] = [];
        $GLOBALS['poll_state']['rest_api']['custom_metrics'] = [];

        foreach ($this->device->restApiConnections as $connection) {
            // ... (rest of polling loop remains the same) ...

            foreach ($connection->endpoints as $endpoint) {
                // ... (API call and mapping logic remains the same) ...

                if ($body && $endpoint->metric_map) {
                    $this->mapData($endpoint, $body);
                }

                $endpoint->update(['last_polled' => Carbon::now()]);

                $this->updateRateLimit($connection);
            }
        }

        $this->storeCustomMetrics();
    }

    // ... (mapData and storeMetric remain the same, but ensuring custom_metrics has Carbon object for collected_at) ...

    protected function storeMetric(RestApiEndpoint $endpoint, string $metricName, $value)
    {
        try {
            if (!is_scalar($value) && !is_array($value) && !is_null($value)) {
                Log::warning("Invalid metric value type for {$metricName}: " . gettype($value));
                return;
            }

            $lowerMetricName = Str::lower($metricName);
            $storageValue = is_scalar($value) ? $value : json_encode($value);

            if ($storageValue === null) {
                 $storageValue = '';
            }

            if ($this->isCoreMetric($lowerMetricName)) {
                $GLOBALS['poll_state']['rest_api']['metrics'][$metricName] = $value;
                Log::debug("[REST API STORE] Metric '{$metricName}' (Core Match).");
                return;
            }

            $collectedAt = Carbon::now();

            $GLOBALS['poll_state']['rest_api']['custom_metrics'][] = [
                'endpoint_id' => $endpoint->id,
                'metric_name' => $metricName,
                'metric_value' => $storageValue,
                'collected_at' => $collectedAt, // Store Carbon object
            ];

            Log::debug("[REST API STORE] Metric '{$metricName}' (Custom Fallback).");

        } catch (\Exception $e) {
            Log::error("Error in storeMetric {$metricName}: " . $e->getMessage());
        }
    }

    /**
     * Batch inserts all custom metrics collected during the poll run using DB::insert.
     */
    protected function storeCustomMetrics(): void
    {
        if (!empty($GLOBALS['poll_state']['rest_api']['custom_metrics'])) {
            $metricsToInsert = $GLOBALS['poll_state']['rest_api']['custom_metrics'];

            $now = Carbon::now();
            $nowString = $now->toDateTimeString();

            $values = [];
            $columns = ['endpoint_id', 'metric_name', 'metric_value', 'collected_at', 'created_at', 'updated_at'];
            $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

            foreach ($metricsToInsert as $metric) {
                // Ensure collected_at is a string
                $collectedAtString = $metric['collected_at'] instanceof Carbon
                                     ? $metric['collected_at']->toDateTimeString()
                                     : $metric['collected_at'];

                $values[] = $metric['endpoint_id'];
                $values[] = $metric['metric_name'];
                $values[] = $metric['metric_value'];
                $values[] = $collectedAtString;
                $values[] = $nowString; // created_at
                $values[] = $nowString; // updated_at
            }

            // Build the SQL insert query
            $insert_query = 'INSERT INTO rest_api_metrics (' . implode(', ', $columns) . ') VALUES ';
            $insert_query .= implode(', ', array_fill(0, count($metricsToInsert), $placeholders));

            try {
                // Execute the raw query
                DB::insert($insert_query, $values);

                Log::info("Successfully inserted " . count($metricsToInsert) . " custom REST API metrics using DB::insert.");
            } catch (\Illuminate\Database\QueryException $e) {
                Log::error("CRITICAL DB FAILURE IN BATCH INSERT: " . $e->getMessage(), ['metrics_count' => count($metricsToInsert)]);
            } catch (\Exception $e) {
                 Log::error("CRITICAL DB FAILURE (General Exception): " . $e->getMessage(), ['metrics_count' => count($metricsToInsert)]);
            }
        }
    }

    // ... (All other helper functions must be present here: isCoreMetric, checkRateLimit, updateRateLimit, etc.) ...

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

    protected function checkRateLimit($connection): bool { return true; }
    protected function updateRateLimit($connection): void { }
    protected function handleFailedEndpoint(RestApiEndpoint $endpoint): void { }
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