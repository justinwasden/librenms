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
    public function pollAllDevices(): void
    {
        RestApiConnection::where('enabled', true)
            ->with(['device', 'endpoints.mappings'])
            ->chunk(20, function ($connections) {
                foreach ($connections as $connection) {
                    $this->pollDeviceConnection($connection);
                }
            });
    }

    public function pollDeviceConnection(RestApiConnection $connection): void
    {
        foreach ($connection->endpoints->where('enabled', true) as $endpoint) {
            try {
                $this->processEndpoint($connection, $endpoint);
            } catch (\Throwable $e) {
                Log::error("REST poll error for {$connection->device->hostname} ({$endpoint->path}): {$e->getMessage()}");
            }
        }
    }

    protected function processEndpoint(RestApiConnection $connection, $endpoint): void
    {
        $url = rtrim($connection->base_url, '/') . $endpoint->path;

        $response = Http::withOptions([
                'verify' => !$connection->disable_ssl_verify,
                'timeout' => 30,
            ])->get($url);

        if (!$response->successful()) {
            throw new \Exception("HTTP {$response->status()} from {$url}");
        }

        $data = $response->json();

        foreach ($endpoint->mappings->where('enabled', true) as $mapping) {
            $value = Arr::get($data, $mapping->api_field);
            if (is_null($value)) continue;

            $this->applyValue(
                $connection->device_id,
                $mapping->librenms_table,
                $mapping->librenms_field,
                $value
            );
        }
    }

    protected function applyValue($deviceId, $table, $column, $value): void
    {
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
            ->with(['endpoints.mappings'])
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
