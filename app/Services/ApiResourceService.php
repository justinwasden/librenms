<?php

namespace App\Services;

use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiResourceService
{

    public function normalizeInterfaceItem(array $item): array
    {
        $eth = Arr::get($item, 'eth', []);
        $ifName = Arr::get($item, 'name', Arr::get($eth, 'name', null));
        $ifDescr = Arr::get($eth, 'description', Arr::get($item, 'display_name', $ifName)) ?? $ifName;
        $ifSpeed = Arr::get($item, 'speed', Arr::get($eth, 'speed', 0));
        $ifMtu = Arr::get($eth, 'mtu', 1500);
        $ifType = $this->mapIfType(Arr::get($item, 'interface_type') ?? Arr::get($eth, 'subtype') ?? 'ethernetCsmacd');
        $ifAdmin = Arr::get($item, 'enabled', true) ? 'up' : 'down';
        $ifOper = $ifAdmin;

        return [
            'ifName' => $ifName,
            'ifDescr' => $ifDescr,
            'ifAlias' => Arr::get($item, 'alias', ''),
            'ifType' => $ifType,
            'ifSpeed' => $ifSpeed,
            'ifMtu' => $ifMtu,
            'ifAdminStatus' => $ifAdmin,
            'ifOperStatus' => $ifOper,
            'ifPhysAddress' => Arr::get($eth, 'mac_address', Arr::get($item, 'mac', '')),
            'port_descr_type' => 'rest-api',
            'deleted' => 0,
            'poll_time' => time(),
            'poll_prev' => time(),
            'poll_period' => 300,
        ];
    }

    protected function mapIfType(string $t): string
    {
        $t = strtolower($t);
        if (str_contains($t, 'eth') || str_contains($t, 'network') || str_contains($t, 'physical')) {
            return 'ethernetCsmacd';
        }
        if (str_contains($t, 'fc') || str_contains($t, 'fibre')) {
            return 'propVirtual';
        }
        return 'other';
    }

    public function normalizeStorageItem(array $item, string $preferName = null): array
    {
        $name = $preferName ?? Arr::get($item, 'name', Arr::get($item, 'id', 'unknown'));
        $space = Arr::get($item, 'space', []);
        $totalPhysical = (float) Arr::get($space, 'total_physical', Arr::get($item, 'total_physical', 0));
        $totalProvisioned = (float) Arr::get($space, 'total_provisioned', Arr::get($item, 'total_provisioned', 0));
        $totalUsed = (float) Arr::get($space, 'total_used', Arr::get($item, 'total_used', 0));
        $type = Arr::get($item, 'subtype', Arr::get($item, 'type', 'volume'));

        // prefer provisioned capacity as "storage_size" if present, else physical
        $size = $totalProvisioned ?: $totalPhysical;

        $used = $totalUsed;
        $free = max(0, $size - $used);

        return [
            'storage_descr' => $name,
            'storage_index' => crc32($name) & 0x7FFFFFFF,
            'storage_type' => $type,
            'storage_size' => (int)$size,
            'storage_used' => (int)$used,
            'storage_free' => (int)$free,
            'storage_units' => 1,
            'storage_perc' => $size > 0 ? round(($used / $size) * 100, 2) : 0,
            'deleted' => 0,
        ];
    }

    public function normalizeControllerItem(array $item): array
    {
        $name = Arr::get($item, 'name', Arr::get($item, 'id', 'controller'));
        $status = strtolower(Arr::get($item, 'status', 'unknown'));
        $statusInt = in_array($status, ['ready', 'ok', 'online', 'up']) ? 1 : 0;

        return [
            'label' => $name,
            'type' => Arr::get($item, 'type', 'controller'),
            'status' => $statusInt,
            'info' => Arr::get($item, 'model', '') . ' ' . Arr::get($item, 'version', ''),
            'deleted' => 0,
        ];
    }

    public function normalizeHostItem(array $item): array
    {
        $name = Arr::get($item, 'name', Arr::get($item, 'id', 'host'));
        $space = Arr::get($item, 'space', []);
        $size = (int) Arr::get($space, 'total_physical', 0);
        $used = (int) Arr::get($space, 'total_used', 0);
        $free = max(0, $size - $used);

        return [
            'storage_descr' => $name,
            'storage_index' => crc32($name) & 0x7FFFFFFF,
            'storage_type' => 'host',
            'storage_size' => $size,
            'storage_used' => $used,
            'storage_free' => $free,
            'storage_units' => 1,
            'storage_perc' => $size > 0 ? round(($used / $size) * 100, 2) : 0,
            'deleted' => 0,
        ];
    }

    public function normalizeSensorFromMetric(array $metricRow): array
    {
        $metricName = $metricRow['metric_name'] ?? '';
        $resourceName = $metricRow['resource_name'] ?? '';
        $resourceId = $metricRow['resource_id'] ?? '';

        $class = $this->determineSensorClass($metricName);
        $index = 'api-' . ($resourceId ?: md5($metricName . $resourceName));

        return [
            'sensor_class' => $class,
            'sensor_type' => 'rest-api',
            'sensor_index' => $index,
            'sensor_descr' => $resourceName ?: $metricName,
            'sensor_current' => $metricRow['value'] ?? $metricRow['string_value'] ?? null,
            'poller_type' => 'rest-api',
            'lastupdate' => now(),
            'deleted' => 0,
        ];
    }

    public function determineSensorClass(string $metricName): string
    {
        $m = strtolower(str_replace(['-', ' '], '_', $metricName));

        if (str_contains($m, 'temp') || str_contains($m, 'temperature') || $m === 'tmp') return 'temperature';
        if (str_contains($m, 'iops') || str_contains($m, 'reads') || str_contains($m, 'writes') || str_contains($m, 'connections')) return 'count';
        if (str_contains($m, 'lat') || str_contains($m, 'usec') || str_contains($m, 'ms') || str_contains($m, 'delay')) return 'delay';
        if (str_contains($m, 'reduction') || str_contains($m, 'ratio')) return 'ratio';
        if (str_contains($m, 'power') || str_contains($m, 'watt')) return 'power';
        if (str_contains($m, 'volt')) return 'voltage';
        if (str_contains($m, 'fan') || str_contains($m, 'rpm')) return 'fanspeed';
        if (str_contains($m, 'bytes_per_sec') || str_contains($m, 'throughput') || str_contains($m, 'bandwidth')) return 'count';
        return 'state';
    }

    public function stageMetrics(Device $device, string $resourceType, string $resourceId, string $resourceName, array $metrics, ?int $endpointId = null, ?int $connectionId = null): void
    {
        $collectedAt = Carbon::now();

        // Fetch existing metrics for this resource+endpoint
        $existing = DB::table('device_api_metrics')
            ->where('device_id', $device->device_id)
            ->when($endpointId, fn($q) => $q->where('api_endpoint_id', $endpointId))
            ->where('resource_id', $resourceId)
            ->get()
            ->keyBy('metric_name');

        $processed = [];

        $insert = [];
        $update = [];
        foreach ($metrics as $metricName => $value) {
            $processed[] = $metricName;
            $isNumeric = is_numeric($value);
            $numericValue = $isNumeric ? (float)$value : null;
            $stringValue = $isNumeric ? null : (is_null($value) ? null : (string)$value);

            if (isset($existing[$metricName])) {
                $row = $existing[$metricName];
                // compare
                $changed = false;
                if ($isNumeric) {
                    $changed = abs(($row->value ?? 0) - $numericValue) > 0.0001;
                } else {
                    $changed = ($row->string_value ?? '') !== ($stringValue ?? '');
                }

                if ($changed) {
                    $update[] = [
                        'id' => $row->id,
                        'value' => $numericValue,
                        'string_value' => $stringValue,
                        'collected_at' => $collectedAt,
                        'updated_at' => $collectedAt,
                    ];
                } else {
                    // touch collected_at
                    DB::table('device_api_metrics')->where('id', $row->id)->update([
                        'collected_at' => $collectedAt,
                        'updated_at' => $collectedAt,
                    ]);
                }
            } else {
                $insert[] = [
                    'device_id' => $device->device_id,
                    'api_endpoint_id' => $endpointId,
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
        }

        // delete obsolete metrics (present in DB but not in current payload)
        $toDelete = collect($existing->keys())->diff($processed);
        if ($toDelete->isNotEmpty()) {
            DB::table('device_api_metrics')
                ->where('device_id', $device->device_id)
                ->when($endpointId, fn($q) => $q->where('api_endpoint_id', $endpointId))
                ->where('resource_id', $resourceId)
                ->whereIn('metric_name', $toDelete->values()->toArray())
                ->delete();
            Log::info("Deleted {$toDelete->count()} stale metrics for resource {$resourceName} ({$resourceType}) on {$device->hostname}");
        }

        if (!empty($insert)) {
            try {
                DB::table('device_api_metrics')->insert($insert);
            } catch (\Exception $e) {
                Log::error("Failed to insert device_api_metrics: " . $e->getMessage());
            }
        }

        if (!empty($update)) {
            foreach ($update as $u) {
                try {
                    DB::table('device_api_metrics')->where('id', $u['id'])->update([
                        'value' => $u['value'],
                        'string_value' => $u['string_value'],
                        'collected_at' => $u['collected_at'],
                        'updated_at' => $u['updated_at'],
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to update device_api_metrics id {$u['id']}: " . $e->getMessage());
                }
            }
        }
    }

    public function persistPorts(Device $device, array $normalizedPorts): int
    {
        $count = 0;
        foreach ($normalizedPorts as $p) {
            if (empty($p['ifName'])) continue;

            DB::table('ports')->updateOrInsert(
                [
                    'device_id' => $device->device_id,
                    'ifName' => $p['ifName'],
                ],
                array_merge($p, ['device_id' => $device->device_id])
            );
            $count++;
        }
        return $count;
    }

    public function persistStorage(Device $device, array $normalizedStorage): int
    {
        $count = 0;
        foreach ($normalizedStorage as $s) {
            DB::table('storage')->updateOrInsert(
                [
                    'device_id' => $device->device_id,
                    'storage_descr' => $s['storage_descr'],
                ],
                array_merge($s, ['device_id' => $device->device_id])
            );
            $count++;
        }
        return $count;
    }

   public function persistComponents(Device $device, array $components): int
    {
        $count = 0;
        foreach ($components as $c) {
            DB::table('component')->updateOrInsert(
                ['device_id' => $device->device_id, 'label' => $c['label']],
                array_merge($c, ['device_id' => $device->device_id])
            );
            $count++;
        }
        return $count;
    }

    public function persistSensors(Device $device, array $sensors): int
    {
        $count = 0;
        foreach ($sensors as $s) {
            DB::table('sensors')->updateOrInsert(
                [
                    'device_id' => $device->device_id,
                    'sensor_class' => $s['sensor_class'],
                    'sensor_index' => $s['sensor_index'],
                    'sensor_type' => $s['sensor_type'],
                ],
                array_merge($s, ['device_id' => $device->device_id])
            );
            $count++;
        }
        return $count;
    }
}
