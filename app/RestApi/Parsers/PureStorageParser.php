<?php

namespace App\Parsers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PureStorageParser
{

    public function parseResponse(array $response, ?string $resource = null): array
    {
        $parsed = [];

        // Determine resource if not provided
        $resource = $resource ?? $this->detectResourceType($response);

        // Many Pure APIs wrap data under 'items'
        if (isset($response['items']) && is_array($response['items'])) {
            foreach ($response['items'] as $index => $item) {
                $flattened = $this->flattenArray($item);
                $parsed[] = [
                    'resource_type' => $resource,
                    'index'         => $index,
                    'metrics'       => $flattened,
                ];
            }
        } else {
            // Direct structure (some endpoints return top-level data)
            $flattened = $this->flattenArray($response);
            $parsed[] = [
                'resource_type' => $resource,
                'index'         => 0,
                'metrics'       => $flattened,
            ];
        }

        return $parsed;
    }

    protected function flattenArray(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}_{$key}" : $key;

            if (is_array($value)) {
                // Recursively flatten nested structures
                $result += $this->flattenArray($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    protected function detectResourceType(array $response): string
    {
        // Handle "items" wrapper
        if (isset($response['items']) && is_array($response['items'])) {
            $first = $response['items'][0] ?? [];
            return $this->detectResourceType($first);
        }

        $keys = array_keys($response);

        if (Arr::has($response, 'space.total_physical')) {
            return 'arrays';
        }
        if (Arr::has($response, 'network_interface') || in_array('rx_bytes_per_sec', $keys)) {
            return 'network-interfaces';
        }
        if (Arr::has($response, 'volume_id') || Arr::has($response, 'writes_per_sec')) {
            return 'volumes';
        }
        if (Arr::has($response, 'controller') || Arr::has($response, 'cpu')) {
            return 'controllers';
        }

        return 'unknown';
    }

		public function predictMapping(array $sampleMetrics, string $resource): array
		{
		    $mapping = [];

		    foreach ($sampleMetrics as $key => $value) {
		        $lowerKey = strtolower($key);

		        // --- STORAGE / SPACE METRICS ---
		        if (Str::contains($lowerKey, 'total_provisioned')) {
		            $mapping["storage_size_gib"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_size',
		                'conversion' => ['divide' => 1073741824] // bytes  GiB
		            ];
		        } elseif (Str::contains($lowerKey, 'total_used')) {
		            $mapping["storage_used_gib"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_used',
		                'conversion' => ['divide' => 1073741824]
		            ];
		        } elseif (Str::contains($lowerKey, 'used_provisioned')) {
		            $mapping["storage_used_provisioned_gib"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_used',
		                'conversion' => ['divide' => 1073741824]
		            ];
		        } elseif (Str::contains($lowerKey, 'capacity')) {
		            $mapping["storage_capacity_gib"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_size',
		                'conversion' => ['divide' => 1073741824]
		            ];
		        } elseif (Str::contains($lowerKey, 'data_reduction')) {
		            $mapping["storage_data_reduction_ratio"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_perc',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- PERFORMANCE / SENSOR METRICS ---
		        elseif (Str::contains($lowerKey, 'reads_per_sec')) {
		            $mapping["sensor_read_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'writes_per_sec')) {
		            $mapping["sensor_write_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'read_bytes_per_sec')) {
		            $mapping["sensor_read_bandwidth_mbps"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['multiply' => 8, 'divide' => 1000000] // Mbps
		            ];
		        } elseif (Str::contains($lowerKey, 'write_bytes_per_sec')) {
		            $mapping["sensor_write_bandwidth_mbps"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['multiply' => 8, 'divide' => 1000000]
		            ];
		        } elseif (Str::contains($lowerKey, 'usec_per_read_op')) {
		            $mapping["sensor_read_latency_ms"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['divide' => 1000] // µs  ms
		            ];
		        } elseif (Str::contains($lowerKey, 'usec_per_write_op')) {
		            $mapping["sensor_write_latency_ms"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['divide' => 1000]
		            ];
		        } elseif (Str::contains($lowerKey, 'queue_depth')) {
		            $mapping["sensor_queue_depth"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- NETWORK INTERFACE PERFORMANCE ---
		        elseif (Str::contains($lowerKey, 'received_bytes_per_sec')) {
		            $mapping["ifInOctets_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInOctets_rate',
		                'conversion' => ['multiply' => 8] // bytes  bits/sec
		            ];
		        } elseif (Str::contains($lowerKey, 'transmitted_bytes_per_sec')) {
		            $mapping["ifOutOctets_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifOutOctets_rate',
		                'conversion' => ['multiply' => 8]
		            ];
		        } elseif (Str::contains($lowerKey, 'received_packets_per_sec')) {
		            $mapping["ifInUcastPkts_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInUcastPkts_rate',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'transmitted_packets_per_sec')) {
		            $mapping["ifOutUcastPkts_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifOutUcastPkts_rate',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'total_errors_per_sec')) {
		            $mapping["ifErrors_rate_total"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInErrors_rate',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- HARDWARE / ENVIRONMENTAL SENSORS ---
		        elseif (Str::contains($lowerKey, 'temperature')) {
		            $mapping["sensor_temperature_celsius"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'voltage')) {
		            $mapping["sensor_voltage_volts"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'power')) {
		            $mapping["sensor_optical_power_dbm"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'status')) {
		            $mapping["entStateOper_status"] = [
		                'source_field' => $key,
		                'target_field' => 'entStateOper',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'serial')) {
		            $mapping["entPhysicalSerialNum"] = [
		                'source_field' => $key,
		                'target_field' => 'entPhysicalSerialNum',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'model')) {
		            $mapping["entPhysicalModelName"] = [
		                'source_field' => $key,
		                'target_field' => 'entPhysicalModelName',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'name')) {
		            $mapping["entPhysicalName"] = [
		                'source_field' => $key,
		                'target_field' => 'entPhysicalName',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- VOLUME METRICS ---
		        elseif (Str::contains($lowerKey, 'volume') && Str::contains($lowerKey, 'read')) {
		            $mapping["volume_read_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'volume') && Str::contains($lowerKey, 'write')) {
		            $mapping["volume_write_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- FALLBACK ---
		        else {
		            $mapping["{$resource}_{$lowerKey}"] = [
		                'source_field' => $key,
		                'target_field' => 'custom_metric',
		                'conversion' => ['none' => true]
		            ];
		        }
		    }

		    return $mapping;
		}

		public function applyConversions(array $mapping, array $metrics): array
		{
		    $normalized = [];

		    foreach ($mapping as $target => $info) {
		        $sourceField = $info['source_field'] ?? null;
		        $conversion  = $info['conversion'] ?? ['none' => true];

		        if (!isset($metrics[$sourceField])) {
		            continue;
		        }

		        $value = $metrics[$sourceField];

		        if (!is_numeric($value)) {
		            $normalized[$target] = [
		                'value' => $value,
		                'label' => $value,
		                'unit'  => ''
		            ];
		            continue;
		        }

		        // --- Conversion ---
		        if (isset($conversion['none'])) {
		            $converted = (float) $value;
		        } elseif (isset($conversion['divide']) && isset($conversion['multiply'])) {
		            $converted = ((float) $value * $conversion['multiply']) / $conversion['divide'];
		        } elseif (isset($conversion['divide'])) {
		            $converted = (float) $value / $conversion['divide'];
		        } elseif (isset($conversion['multiply'])) {
		            $converted = (float) $value * $conversion['multiply'];
		        } else {
		            $converted = (float) $value;
		        }

		        $unit = '';
		        $rounded = $converted;

		        switch (true) {
		            case Str::contains($target, ['bandwidth', 'mbps']):
		                $unit = 'Mbps';
		                $rounded = round($converted, 2);
		                break;
		            case Str::contains($target, ['latency', 'ms']):
		                $unit = 'ms';
		                $rounded = round($converted, 3);
		                break;
		            case Str::contains($target, ['iops']):
		                $unit = 'IOPS';
		                $rounded = (int) round($converted);
		                break;
		            case Str::contains($target, ['gib', 'storage', 'capacity', 'used']):
		                $unit = 'GiB';
		                $rounded = round($converted, 2);
		                break;
		            case Str::contains($target, ['temperature']):
		                $unit = '°C';
		                $rounded = round($converted, 1);
		                break;
		            case Str::contains($target, ['voltage']):
		                $unit = 'V';
		                $rounded = round($converted, 2);
		                break;
		            case Str::contains($target, ['power']):
		                $unit = 'dBm';
		                $rounded = round($converted, 2);
		                break;
		            case Str::contains($target, ['ratio', 'perc']):
		                $unit = '%';
		                $rounded = round($converted, 2);
		                break;
		            default:
		                $unit = '';
		                $rounded = is_int($converted) ? $converted : round($converted, 2);
		        }

		        $normalized[$target] = [
		            'value' => $rounded,
		            'label' => $unit ? "{$rounded} {$unit}" : (string)$rounded,
		            'unit'  => $unit
		        ];
		    }

		    return $normalized;
		}

		public function storeNormalizedMetrics(array $normalizedMetrics, int $deviceId): void
		{
		    foreach ($normalizedMetrics as $key => $data) {
		        // Determine which table to use based on prefix
		        $table = null;
		        $field = $key;

		        if (Str::startsWith($key, 'storage_')) {
		            $table = 'storage';
		            $field = Str::after($key, 'storage_');
		        } elseif (Str::startsWith($key, 'sensor_')) {
		            $table = 'sensors';
		            $field = Str::after($key, 'sensor_');
		        } elseif (Str::startsWith($key, 'ifin') || Str::startsWith($key, 'ifout')) {
		            $table = 'ports_statistics';
		            $field = $key; // Keep ifIn/ifOut naming
		        } elseif (Str::startsWith($key, 'ent')) {
		            $table = 'entPhysical';
		            $field = Str::after($key, 'ent');
		        } elseif (Str::startsWith($key, 'volume_')) {
		            $table = 'pure_storage_metrics';
		            $field = Str::after($key, 'volume_');
		        } else {
		            // Fallback: store in custom Pure Storage metrics table
		            $table = 'pure_storage_metrics';
		        }

		        $value = $data['value'] ?? null;
		        $unit  = $data['unit'] ?? '';

		        if ($value === null) {
		            continue;
		        }

		        // --- STORAGE TABLE ---
		        if ($table === 'storage') {
		            \DB::table('storage')->updateOrInsert(
		                ['device_id' => $deviceId],
		                [$field => $value]
		            );
		        }

		        // --- SENSORS TABLE ---
		        elseif ($table === 'sensors') {
		            \DB::table('sensors')->updateOrInsert(
		                [
		                    'device_id' => $deviceId,
		                    'sensor_descr' => ucfirst(str_replace('_', ' ', $field)),
		                ],
		                [
		                    'sensor_current' => $value,
		                    'sensor_unit'    => $unit,
		                    'lastupdate'     => now(),
		                ]
		            );
		        }

		        // --- PORTS / INTERFACE STATS ---
		        elseif ($table === 'ports_statistics') {
		            \DB::table('ports_statistics')->updateOrInsert(
		                ['device_id' => $deviceId],
		                [$field => $value]
		            );
		        }

		        // --- ENTITY / HARDWARE METRICS ---
		        elseif ($table === 'entPhysical') {
		            \DB::table('entPhysical')->updateOrInsert(
		                ['device_id' => $deviceId],
		                [$field => $value]
		            );
		        }

		        // --- PURE STORAGE CUSTOM TABLE ---
		        elseif ($table === 'pure_storage_metrics') {
		            \DB::table('pure_storage_metrics')->updateOrInsert(
		                [
		                    'device_id' => $deviceId,
		                    'metric_name' => $field,
		                ],
		                [
		                    'metric_value' => $value,
		                    'metric_unit'  => $unit,
		                    'updated_at'   => now(),
		                ]
		            );
		        }
		    }
		}
}
