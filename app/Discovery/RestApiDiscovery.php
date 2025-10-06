<?php

namespace App\Discovery;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestApiDiscovery
{
    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Discover all REST API resources for a device
     */
    public function discover(): array
    {
        $stats = [
            'ports' => 0,
            'storage' => 0,
            'sensors' => 0,
            'processors' => 0,
            'mempools' => 0,
        ];

        if (!$this->device->restApiConnections()->where('enabled', 1)->exists()) {
            return $stats;
        }

        Log::info("Starting REST API discovery for device {$this->device->hostname}");

        // Discover from collected metrics
        $stats['ports'] = $this->discoverPorts();
        $stats['storage'] = $this->discoverStorage();
        $stats['sensors'] = $this->discoverSensors();
        $stats['processors'] = $this->discoverProcessors();
        $stats['mempools'] = $this->discoverMemPools();

        Log::info("REST API discovery complete for {$this->device->hostname}", $stats);

        return $stats;
    }

    /**
     * Discover ports/interfaces from REST API metrics
     */
    protected function discoverPorts(): int
    {
        $discovered = 0;

        // Get interface metrics from device_api_metrics
        $interfaces = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', 'if%')
                  ->orWhere('metric_name', 'like', '%interface%')
                  ->orWhere('metric_name', 'like', '%port%')
                  ->orWhere('resource_type', 'port')
                  ->orWhere('resource_type', 'interface');
            })
            ->select('resource_id', 'resource_name')
            ->distinct()
            ->get();

        foreach ($interfaces as $interface) {
            if (!$interface->resource_id) {
                continue;
            }

            // Check if port already exists
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

            // Get metrics for this interface
            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('resource_id', $interface->resource_id)
                ->get()
                ->keyBy('metric_name');

            // Create new port
            $portId = DB::table('ports')->insertGetId([
                'device_id' => $this->device->device_id,
                'port_descr_type' => 'rest-api',
                'ifName' => $interface->resource_name ?? $interface->resource_id,
                'ifDescr' => $interface->resource_name ?? $interface->resource_id,
                'ifAlias' => '',
                'ifIndex' => $this->generateIfIndex($interface->resource_id),
                'ifSpeed' => $this->getMetricValue($metrics, ['ifSpeed', 'speed', 'port_speed'], 0),
                'ifOperStatus' => $this->mapOperStatus($this->getMetricValue($metrics, ['ifOperStatus', 'oper_status', 'status'], 'up')),
                'ifAdminStatus' => $this->mapOperStatus($this->getMetricValue($metrics, ['ifAdminStatus', 'admin_status'], 'up')),
                'ifMtu' => $this->getMetricValue($metrics, ['ifMtu', 'mtu'], 1500),
                'ifType' => $this->getMetricValue($metrics, ['ifType', 'type'], 'ethernetCsmacd'),
                'ifPhysAddress' => $this->getMetricValue($metrics, ['ifPhysAddress', 'mac_address'], ''),
                'poll_time' => now(),
                'poll_prev' => 0,
                'poll_period' => 300,
            ]);

            Log::info("Discovered port {$interface->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    /**
     * Discover storage/volumes from REST API metrics
     */
    protected function discoverStorage(): int
    {
        $discovered = 0;

        // Get storage metrics
        $volumes = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%volume%')
                  ->orWhere('metric_name', 'like', '%storage%')
                  ->orWhere('metric_name', 'like', '%capacity%')
                  ->orWhere('metric_name', 'like', '%disk%')
                  ->orWhere('resource_type', 'volume')
                  ->orWhere('resource_type', 'storage')
                  ->orWhere('resource_type', 'disk');
            })
            ->select('resource_id', 'resource_name')
            ->distinct()
            ->get();

        foreach ($volumes as $volume) {
            if (!$volume->resource_id) {
                continue;
            }

            // Check if storage already exists
            $exists = DB::table('storage')
                ->where('device_id', $this->device->device_id)
                ->where('storage_descr', $volume->resource_name)
                ->exists();

            if ($exists) {
                continue;
            }

            // Get metrics for this volume
            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('resource_id', $volume->resource_id)
                ->get()
                ->keyBy('metric_name');

            $total = $this->getMetricValue($metrics, ['volume_provisioned', 'capacity', 'size', 'total'], 0);
            $used = $this->getMetricValue($metrics, ['volume_used', 'used'], 0);
            $free = $total - $used;

            // Create storage entry
            DB::table('storage')->insert([
                'device_id' => $this->device->device_id,
                'storage_mib' => 'rest-api',
                'storage_index' => $this->generateStorageIndex($volume->resource_id),
                'storage_type' => 'rest-api',
                'storage_descr' => $volume->resource_name ?? $volume->resource_id,
                'storage_size' => $total,
                'storage_units' => 1,
                'storage_used' => $used,
                'storage_free' => $free,
                'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
            ]);

            Log::info("Discovered storage {$volume->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    /**
     * Discover sensors from REST API metrics
     */
    protected function discoverSensors(): int
    {
        $discovered = 0;

        // Get sensor-like metrics
        $sensorMetrics = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%temperature%')
                  ->orWhere('metric_name', 'like', '%temp%')
                  ->orWhere('metric_name', 'like', '%power%')
                  ->orWhere('metric_name', 'like', '%voltage%')
                  ->orWhere('metric_name', 'like', '%current%')
                  ->orWhere('metric_name', 'like', '%fan%')
                  ->orWhere('metric_name', 'like', '%humidity%')
                  ->orWhere('metric_name', 'like', '%reduction%')
                  ->orWhere('metric_name', 'like', '%iops%')
                  ->orWhere('metric_name', 'like', '%latency%')
                  ->orWhere('metric_name', 'like', '%bandwidth%')
                  ->orWhere('metric_name', 'like', '%connections%')
                  ->orWhere('resource_type', 'sensor');
            })
            ->whereNotNull('value')
            ->select('resource_id', 'resource_name', 'metric_name', 'value')
            ->distinct()
            ->get();

        foreach ($sensorMetrics as $metric) {
            $sensorClass = $this->determineSensorClass($metric->metric_name);
            $sensorIndex = $this->generateSensorIndex($metric);

            // Check if sensor already exists
            $exists = DB::table('sensors')
                ->where('device_id', $this->device->device_id)
                ->where('sensor_class', $sensorClass)
                ->where('sensor_index', $sensorIndex)
                ->where('sensor_type', 'rest-api')
                ->exists();

            if ($exists) {
                continue;
            }

            // Create sensor
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
                'sensor_limit' => $this->getSensorLimit($sensorClass, 'high'),
                'sensor_limit_low' => $this->getSensorLimit($sensorClass, 'low'),
                'sensor_limit_warn' => null,
                'sensor_limit_low_warn' => null,
                'lastupdate' => now(),
            ]);

            Log::info("Discovered sensor {$metric->metric_name} ({$sensorClass}) for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    /**
     * Discover processors from REST API metrics
     */
    protected function discoverProcessors(): int
    {
        $discovered = 0;

        $processors = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%cpu%')
                  ->orWhere('metric_name', 'like', '%processor%')
                  ->orWhere('resource_type', 'processor')
                  ->orWhere('resource_type', 'cpu');
            })
            ->select('resource_id', 'resource_name', 'value')
            ->distinct()
            ->get();

        foreach ($processors as $processor) {
            if (!$processor->resource_id) {
                continue;
            }

            $exists = DB::table('processors')
                ->where('device_id', $this->device->device_id)
                ->where('processor_descr', $processor->resource_name)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('processors')->insert([
                'device_id' => $this->device->device_id,
                'processor_index' => $this->generateProcessorIndex($processor->resource_id),
                'processor_type' => 'rest-api',
                'processor_descr' => $processor->resource_name ?? $processor->resource_id,
                'processor_usage' => $processor->value ?? 0,
            ]);

            Log::info("Discovered processor {$processor->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    /**
     * Discover memory pools from REST API metrics
     */
    protected function discoverMemPools(): int
    {
        $discovered = 0;

        $mempools = DB::table('device_api_metrics')
            ->where('device_id', $this->device->device_id)
            ->where(function ($q) {
                $q->where('metric_name', 'like', '%memory%')
                  ->orWhere('metric_name', 'like', '%mem%')
                  ->orWhere('metric_name', 'like', '%ram%')
                  ->orWhere('resource_type', 'memory')
                  ->orWhere('resource_type', 'mempool');
            })
            ->select('resource_id', 'resource_name')
            ->distinct()
            ->get();

        foreach ($mempools as $mempool) {
            if (!$mempool->resource_id) {
                continue;
            }

            $exists = DB::table('mempools')
                ->where('device_id', $this->device->device_id)
                ->where('mempool_descr', $mempool->resource_name)
                ->exists();

            if ($exists) {
                continue;
            }

            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $this->device->device_id)
                ->where('resource_id', $mempool->resource_id)
                ->get()
                ->keyBy('metric_name');

            $total = $this->getMetricValue($metrics, ['memory_total', 'mem_total', 'total'], 0);
            $used = $this->getMetricValue($metrics, ['memory_used', 'mem_used', 'used'], 0);
            $free = $total - $used;

            DB::table('mempools')->insert([
                'device_id' => $this->device->device_id,
                'mempool_index' => $this->generateMempoolIndex($mempool->resource_id),
                'mempool_type' => 'rest-api',
                'mempool_descr' => $mempool->resource_name ?? $mempool->resource_id,
                'mempool_total' => $total,
                'mempool_used' => $used,
                'mempool_free' => $free,
                'mempool_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
            ]);

            Log::info("Discovered mempool {$mempool->resource_name} for device {$this->device->hostname}");
            $discovered++;
        }

        return $discovered;
    }

    // Helper methods

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
            'active' => 'up',
            'connected' => 'up',
            'down' => 'down',
            'offline' => 'down',
            'inactive' => 'down',
            'disabled' => 'down',
        ];

        return $statusMap[strtolower($status)] ?? 'unknown';
    }

    protected function determineSensorClass(string $metricName): string
    {
        $metricName = strtolower($metricName);

        $classMap = [
            'temperature' => 'temperature',
            'temp' => 'temperature',
            'power' => 'power',
            'voltage' => 'voltage',
            'volt' => 'voltage',
            'current' => 'current',
            'ampere' => 'current',
            'fan' => 'fanspeed',
            'humidity' => 'humidity',
            'reduction' => 'ratio',
            'iops' => 'count',
            'latency' => 'delay',
            'bandwidth' => 'load',
            'connections' => 'count',
        ];

        foreach ($classMap as $keyword => $class) {
            if (str_contains($metricName, $keyword)) {
                return $class;
            }
        }

        return 'count';
    }

    protected function formatSensorDescription($metric): string
    {
        $description = $metric->resource_name ?? $metric->metric_name;
        $description = str_replace('_', ' ', $description);
        return ucwords($description);
    }

    protected function getSensorLimit(string $class, string $type): ?float
    {
        $limits = [
            'temperature' => ['low' => 0, 'high' => 100],
            'power' => ['low' => 0, 'high' => null],
            'voltage' => ['low' => 0, 'high' => null],
            'fanspeed' => ['low' => 0, 'high' => null],
            'humidity' => ['low' => 0, 'high' => 100],
        ];

        return $limits[$class][$type] ?? null;
    }

    protected function generateIfIndex(string $resourceId): int
    {
        return crc32($resourceId) & 0x7FFFFFFF;
    }

    protected function generateStorageIndex(string $resourceId): int
    {
        return crc32($resourceId) & 0x7FFFFFFF;
    }

    protected function generateSensorIndex($metric): string
    {
        return 'api-' . ($metric->resource_id ?? md5($metric->metric_name));
    }

    protected function generateProcessorIndex(string $resourceId): int
    {
        return crc32($resourceId) & 0x7FFFFFFF;
    }

    protected function generateMempoolIndex(string $resourceId): int
    {
        return crc32($resourceId) & 0x7FFFFFFF;
    }
}
