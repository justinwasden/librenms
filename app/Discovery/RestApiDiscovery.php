<?php

namespace App\Discovery;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestApiDiscovery
{
    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public function discover(): array
    {
        $stats = [
            'ports' => 0,
            'storage' => 0,
            'sensors' => 0,
        ];

        if (!$this->device->restApiConnections()->where('enabled', 1)->exists()) {
            return $stats;
        }

        Log::info("Starting REST API discovery for device {$this->device->hostname}");

        $stats['ports'] = $this->discoverPorts();
        $stats['storage'] = $this->discoverStorage();
        $stats['sensors'] = $this->discoverSensors();

        Log::info("REST API discovery complete for {$this->device->hostname}", $stats);

        return $stats;
    }

    protected function discoverPorts(): int
    {
        $discovered = 0;

        $interfaces = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', 'if%')
                  ->orWhere('metric_name', 'like', '%interface%')
                  ->orWhere('metric_name', 'like', '%port%');
            })
            ->select('resource_id', 'resource_name')
            ->distinct()
            ->get();

        foreach ($interfaces as $interface) {
            if (!$interface->resource_id) {
                continue;
            }

            $exists = DB::table('ports')
                ->where('device_id', $this->device->device_id)
                ->where(function ($q) use ($interface) {
                    $q->where('ifName', $interface->resource_id)
                      ->orWhere('ifName', $interface->resource_name)
                      ->orWhere('ifDescr', $interface->resource_name);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('resource_id', $interface->resource_id)
                ->get()
                ->keyBy('metric_name');

            DB::table('ports')->insertGetId([
                'device_id' => $this->device->device_id,
                'port_descr_type' => 'rest-api',
                'ifName' => $interface->resource_name ?? $interface->resource_id,
                'ifDescr' => $interface->resource_name ?? $interface->resource_id,
                'ifAlias' => '',
                'ifIndex' => crc32($interface->resource_id) & 0x7FFFFFFF,
                'ifSpeed' => $this->getMetricValue($metrics, ['ifSpeed', 'speed'], 0),
                'ifOperStatus' => $this->mapOperStatus($this->getMetricValue($metrics, ['ifOperStatus', 'status'], 'up')),
                'ifAdminStatus' => $this->mapOperStatus($this->getMetricValue($metrics, ['ifAdminStatus'], 'up')),
                'ifMtu' => $this->getMetricValue($metrics, ['ifMtu', 'mtu'], 1500),
                'ifType' => $this->getMetricValue($metrics, ['ifType', 'type'], 'ethernetCsmacd'),
                'ifPhysAddress' => $this->getMetricValue($metrics, ['ifPhysAddress', 'mac'], ''),
                'poll_time' => time(),
                'poll_prev' => time(),
                'poll_period' => 300,
            ]);

            Log::info("Discovered port {$interface->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    protected function discoverStorage(): int
    {
        $discovered = 0;

        $volumes = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%volume%')
                  ->orWhere('metric_name', 'like', '%storage%')
                  ->orWhere('metric_name', 'like', '%capacity%')
                  ->orWhere('metric_name', 'like', '%provisioned%');
            })
            ->select('resource_id', 'resource_name')
            ->distinct()
            ->get();

        foreach ($volumes as $volume) {
            if (!$volume->resource_id) {
                continue;
            }

            $exists = DB::table('storage')
                ->where('device_id', $this->device->device_id)
                ->where('storage_descr', $volume->resource_name)
                ->exists();

            if ($exists) {
                continue;
            }

            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('resource_id', $volume->resource_id)
                ->get()
                ->keyBy('metric_name');

            $total = $this->getMetricValue($metrics, ['volume_provisioned', 'capacity', 'size', 'total'], 0);
            $used = $this->getMetricValue($metrics, ['volume_used', 'used'], 0);
            $free = $total - $used;

            DB::table('storage')->insert([
                'device_id' => $this->device->device_id,
                'storage_index' => crc32($volume->resource_id) & 0x7FFFFFFF,
                'storage_type' => 'rest-api',
                'storage_descr' => $volume->resource_name ?? $volume->resource_id,
                'storage_size' => $total,
                'storage_units' => 1,
                'storage_used' => $used,
                'storage_free' => $free,
                'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                'type' => 'volume',
            ]);

            Log::info("Discovered storage {$volume->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    protected function discoverSensors(): int
    {
        $discovered = 0;

        $sensorMetrics = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%temperature%')
                  ->orWhere('metric_name', 'like', '%reduction%')
                  ->orWhere('metric_name', 'like', '%iops%')
                  ->orWhere('metric_name', 'like', '%latency%')
                  ->orWhere('metric_name', 'like', '%connections%')
                  ->orWhere('metric_name', 'like', '%snapshots%');
            })
            ->whereNotNull('value')
            ->select('resource_id', 'resource_name', 'metric_name', 'value')
            ->distinct()
            ->get();

        foreach ($sensorMetrics as $metric) {
            $sensorClass = $this->determineSensorClass($metric->metric_name);
            $sensorIndex = 'api-' . ($metric->resource_id ?? md5($metric->metric_name . $metric->resource_name));

            $exists = DB::table('sensors')
                ->where('device_id', $this->device->device_id)
                ->where('sensor_class', $sensorClass)
                ->where('sensor_index', $sensorIndex)
                ->where('sensor_type', 'rest-api')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('sensors')->insert([
                'device_id' => $this->device->device_id,
                'sensor_class' => $sensorClass,
                'sensor_type' => 'rest-api',
                'sensor_index' => $sensorIndex,
                'sensor_descr' => $this->formatSensorDescription($metric),
                'sensor_oid' => '',
                'poller_type' => 'rest-api',
                'sensor_current' => $metric->value,
                'sensor_prev' => null,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'lastupdate' => now(),
            ]);

            Log::info("Discovered sensor {$metric->metric_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    protected function getMetricValue($metrics, array $names, $default = null)
    {
        foreach ($names as $name) {
            if (isset($metrics[$name])) {
                return $metrics[$name]->value ?? $metrics[$name]->string_value ?? $default;
            }
        }
        return $default;
    }

    protected function mapOperStatus(string $status): string
    {
        $statusMap = [
            'up' => 'up',
            'online' => 'up',
            'down' => 'down',
            'offline' => 'down',
        ];

        return $statusMap[strtolower($status)] ?? 'unknown';
    }

    protected function determineSensorClass(string $metricName): string
    {
        $metricName = strtolower($metricName);

        if (str_contains($metricName, 'temperature')) return 'temperature';
        if (str_contains($metricName, 'reduction')) return 'ratio';
        if (str_contains($metricName, 'iops')) return 'count';
        if (str_contains($metricName, 'latency')) return 'delay';
        if (str_contains($metricName, 'connections')) return 'count';
        if (str_contains($metricName, 'snapshots')) return 'count';

        return 'count';
    }

    protected function formatSensorDescription($metric): string
    {
        $description = $metric->resource_name ?? $metric->metric_name;
        $description = str_replace('_', ' ', $description);
        return ucwords($description);
    }
}