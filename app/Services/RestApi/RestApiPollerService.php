<?php

namespace App\Services\RestApi;

use App\Models\RestApiConnection;
use App\Models\RestApiMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RestApiPollerService
{
    /**
     * JSONPath parser - extracts values from arrays using JSONPath notation
     * Supports: $.items[*].field, $.items[0].field, $.field.subfield
     */
    private function extractJsonPath(array $data, string $path): mixed
    {
        // Handle simple direct path
        if (strpos($path, '$') === 0) {
            $path = substr($path, 1);
        }

        if (strpos($path, '.') === 0) {
            $path = substr($path, 1);
        }

        // Handle array wildcard notation like items[*].field
        if (preg_match('/^(\w+)\[\*\]\.(.+)$/', $path, $matches)) {
            $arrayField = $matches[1];
            $subPath = $matches[2];
            
            if (!isset($data[$arrayField]) || !is_array($data[$arrayField])) {
                return null;
            }

            $results = [];
            foreach ($data[$arrayField] as $item) {
                $value = $this->extractJsonPath($item, '.' . $subPath);
                if ($value !== null) {
                    $results[] = $value;
                }
            }
            
            return !empty($results) ? $results : null;
        }

        // Handle numeric array index like items[0]
        if (preg_match('/^(\w+)\[(\d+)\](?:\.(.+))?$/', $path, $matches)) {
            $arrayField = $matches[1];
            $index = (int) $matches[2];
            $subPath = $matches[3] ?? null;

            if (!isset($data[$arrayField][$index])) {
                return null;
            }

            $value = $data[$arrayField][$index];
            
            if ($subPath) {
                return $this->extractJsonPath($value, '.' . $subPath);
            }
            
            return $value;
        }

        // Handle nested path like field.subfield.nested
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function pollAllDevices(): void
    {
        RestApiConnection::where('enabled', true)
            ->with(['device', 'endpoints', 'credential'])
            ->chunk(20, function ($connections) {
                foreach ($connections as $connection) {
                    try {
                        $this->pollDeviceConnection($connection);
                    } catch (\Throwable $e) {
                        Log::error("REST API poll failure for device {$connection->device->hostname}: {$e->getMessage()}");
                    }
                }
            });
    }

    public function pollDeviceConnection(RestApiConnection $connection): void
    {
        foreach ($connection->endpoints()->where('enabled', true)->get() as $endpoint) {
            try {
                $this->processEndpoint($connection, $endpoint);
            } catch (\Throwable $e) {
                Log::error("REST poll error for {$connection->device->hostname} ({$endpoint->path}): {$e->getMessage()}", [
                    'device_id' => $connection->device_id,
                    'endpoint' => $endpoint->path,
                    'exception' => $e,
                ]);
            }
        }
    }

    protected function processEndpoint(RestApiConnection $connection, $endpoint): void
    {
        $url = rtrim($connection->base_url, '/') . $endpoint->path;

        // Build HTTP request with authentication
        $request = Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
        ]);

        // Apply credentials if present
        if ($connection->credential) {
            $request = $this->applyAuthentication($request, $connection->credential);
        }

        $response = $request->get($url);

        if (!$response->successful()) {
            throw new \Exception("HTTP {$response->status()} from {$url}");
        }

        $data = $response->json();

        // Check if response is null or empty
        if ($data === null) {
            Log::warning("API response was null/empty for {$endpoint->path}", [
                'device_id' => $connection->device_id,
                'endpoint' => $endpoint->path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }

        // Get mappings - must be set on endpoint
        $mappings = $endpoint->template_response_mapping;

        if (empty($mappings) || !is_array($mappings)) {
            Log::warning("No template_response_mapping for endpoint {$endpoint->path}", [
                'device_id' => $connection->device_id,
                'endpoint_id' => $endpoint->id,
            ]);
            return;
        }

        foreach ($mappings as $tableField => $apiField) {
            try {
                $this->processMapping($connection, $endpoint, $tableField, $apiField, $data);
            } catch (\Throwable $e) {
                Log::warning("Failed to process mapping {$tableField} <= {$apiField} for {$endpoint->path}: {$e->getMessage()}", [
                    'device_id' => $connection->device_id,
                    'table_field' => $tableField,
                    'api_field' => $apiField,
                ]);
            }
        }
    }

    /**
     * Apply authentication to HTTP request
     */
    protected function applyAuthentication($request, $credential)
    {
        $authType = $credential->authenticationType;

        if (!$authType) {
            return $request;
        }

        $params = $credential->getParamsArray();

        return match (strtolower($authType->name)) {
            'basic' => $request->withBasicAuth($params['username'] ?? '', $params['password'] ?? ''),
            'bearer' => $request->withToken($params['token'] ?? ''),
            'api_key' => $request->withHeaders([
                ($params['header_name'] ?? 'X-API-Key') => ($params['api_key'] ?? ''),
            ]),
            'oauth2' => $request->withToken($params['access_token'] ?? ''),
            'custom' => $request->withHeaders($params),
            default => $request,
        };
    }

    /**
     * Process a single mapping
     * tableField format: "table.field" or "table.field[index]"
     * apiField format: "$.path.to.field" or "$.items[*].field"
     */
    protected function processMapping(RestApiConnection $connection, $endpoint, string $tableField, string $apiField, array $data): void
    {
        // Extract value from API response using JSONPath
        $value = $this->extractJsonPath($data, $apiField);

        if ($value === null || (is_array($value) && empty($value))) {
            return;
        }

        // Parse table.field notation
        list($table, $field) = $this->parseTableField($tableField);

        // If value is an array (from wildcard extraction), process each item
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->applyValue($connection->device_id, $table, $field, $item);
            }
        } else {
            $this->applyValue($connection->device_id, $table, $field, $value);
        }
    }

    /**
     * Parse table.field notation
     * Examples: "devices.hostname", "storage.storage_descr", "ports.ifName"
     */
    private function parseTableField(string $tableField): array
    {
        $parts = explode('.', $tableField, 2);
        
        if (count($parts) !== 2) {
            throw new \Exception("Invalid table.field format: $tableField (must be 'table.field')");
        }

        return [$parts[0], $parts[1]];
    }

    protected function applyValue($deviceId, $table, $column, $value): void
    {
        // Type conversions
        if (is_numeric($value) && !is_string($value)) {
            $value = (int) $value;
        } elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        } else {
            $value = (string) $value;
        }

        switch ($table) {
            case 'devices':
                DB::table('devices')->where('device_id', $deviceId)->update([$column => $value]);
                break;

            case 'storage':
                DB::table('storage')->updateOrInsert(
                    ['device_id' => $deviceId, 'storage_descr' => 'REST Import'],
                    [$column => $value]
                );
                break;

            case 'ports':
                DB::table('ports')->updateOrInsert(
                    ['device_id' => $deviceId, 'ifDescr' => 'REST Interface'],
                    [$column => $value]
                );
                break;

            case 'entPhysical':
                DB::table('entPhysical')->updateOrInsert(
                    ['device_id' => $deviceId, 'entPhysicalName' => 'REST Component'],
                    [$column => $value]
                );
                break;

            case 'sensors':
                DB::table('sensors')->updateOrInsert(
                    ['device_id' => $deviceId, 'sensor_descr' => 'REST Sensor'],
                    [$column => $value]
                );
                break;

            default:
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $column,
                        'endpoint_name' => $table,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
        }
    }

    /**
     * Integrates with native LibreNMS discovery to detect REST-enabled devices.
     */
    public static function discoverRestDevices(): void
    {
        $devices = DB::table('devices')->pluck('device_id');
        foreach ($devices as $deviceId) {
            $hasConnections = DB::table('rest_api_connections')
                ->where('device_id', $deviceId)
                ->where('enabled', true)
                ->exists();

            if ($hasConnections) {
                Log::info("Discovered REST API connection for device ID {$deviceId}.");
            }
        }
    }

    /**
     * Hook for LibreNMS polling engine.
     * If device has REST API endpoints, poll them automatically.
     */
    public static function pollViaLibreNMS($device): void
    {
        $connections = RestApiConnection::where('device_id', $device->device_id)
            ->where('enabled', true)
            ->with(['endpoints', 'credential'])
            ->get();

        if ($connections->isEmpty()) {
            return; // No REST API connections, skip
        }

        $poller = new static();
        foreach ($connections as $connection) {
            $poller->pollDeviceConnection($connection);
        }
    }
}
