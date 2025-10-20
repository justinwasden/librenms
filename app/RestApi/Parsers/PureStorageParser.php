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
                // Extract resource identifier (name, id, etc)
                $resource_name = $item['name'] ?? $item['id'] ?? 'item_' . $index;
                $resource_id = $item['id'] ?? null;
                
                // Pass the resource type for context during flattening
                $flattened = $this->flattenArray($item, '', $resource);
                $parsed[] = [
                    'resource_type' => $resource,
                    'resource_name' => $resource_name,
                    'resource_id' => $resource_id,
                    'metrics' => $flattened,
                ];
            }
        } else {
            // Direct structure (some endpoints return top-level data)
            $flattened = $this->flattenArray($response, '', $resource);
            $parsed[] = [
                'resource_type' => $resource,
                'resource_name' => $response['name'] ?? 'array',
                'resource_id' => $response['id'] ?? null,
                'metrics' => $flattened,
            ];
        }

        return $parsed;
    }
    protected function flattenArray(array $data, string $prefix = '', ?string $resource = null): array
    {
        $result = [];
        $resource_id_map = [];

        // --- 1. Identify and Extract Primary Resource Keys (The fix) ---
        // This is necessary to resolve the LibreNMS foreign keys later (e.g., port_id).

        if (in_array($resource, ['network-interfaces', 'hardware'])) {
            // For interface performance or hardware, we need the main 'name' or 'index' to resolve port/entity.
            if (isset($data['name'])) {
                $result['resource_name'] = $data['name'];
            } elseif (isset($data['index'])) {
                // Often used for hardware/entity index
                $result['resource_index'] = $data['index'];
            }
        } elseif ($resource === 'volumes' && isset($data['name'])) {
             $result['resource_name'] = $data['name'];
        }

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}_{$key}" : $key;

            if (is_array($value) && !empty($value)) {

                // Special handling for nested metric arrays (like 'eth' or 'fc' performance)
                if (in_array($key, ['eth', 'fc', 'space'])) {
                    $result += $this->flattenArray($value, $fullKey, $resource);
                }
                // Special handling for DDM/Threshold lists (like 'temperature', 'voltage') in port-details
                elseif (is_numeric(key($value)) && in_array($key, ['temperature', 'voltage', 'tx_bias', 'tx_power', 'rx_power'])) {
                    // Flatten these measured values by channel, e.g., temperature_measurement_ch1
                    foreach ($value as $channel_data) {
                        $channel = $channel_data['channel'] ?? 'main'; // Use channel or a generic name
                        $measurement = $channel_data['measurement'] ?? null;

                        if ($measurement !== null) {
                             $result["{$key}_measurement_ch{$channel}"] = $measurement;
                             $result["{$key}_status_ch{$channel}"] = $channel_data['status'] ?? 'unknown';
                        }
                    }
                }
                // Nested thresholds (like 'temperature_thresholds')
                elseif (Str::contains($key, 'thresholds') || $key === 'static') {
                    $result += $this->flattenArray($value, $fullKey, $resource);
                }
                // Recurse for general nested structures
                else {
                    $result += $this->flattenArray($value, $fullKey, $resource);
                }
            } else {
                // If the value is not an array, store it
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
        if (Arr::has($response, 'eth_received_bytes_per_sec') || Arr::has($response, 'fc_received_bytes_per_sec')) {
             return 'network-interfaces-performance';
        }
        if (Arr::has($response, 'temperature') && Arr::has($response, 'voltage') && Arr::has($response, 'tx_bias')) {
             return 'network-interfaces-port-details';
        }
        if (Arr::has($response, 'connection_count') || Arr::has($response, 'reads_per_sec')) {
            return 'volumes';
        }
        if (Arr::has($response, 'model') || Arr::has($response, 'serial')) {
            return 'hardware';
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
		        if (Str::contains($lowerKey, 'total_provisioned') || Str::contains($lowerKey, 'capacity')) {
		            $mapping["storage_size"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_size',
		                'conversion' => ['divide' => 1073741824] // bytes to GiB
		            ];
		        } elseif (Str::contains($lowerKey, 'total_used') || Str::contains($lowerKey, 'used_provisioned')) {
		            $mapping["storage_used"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_used',
		                'conversion' => ['divide' => 1073741824] // bytes to GiB
		            ];
		        } elseif (Str::contains($lowerKey, 'data_reduction')) {
		            $mapping["storage_data_reduction_perc"] = [
		                'source_field' => $key,
		                'target_field' => 'storage_perc',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- IOPS / LATENCY / QUEUE (VOLUME/ARRAY PERFORMANCE) ---
		        elseif (Str::contains($lowerKey, 'reads_per_sec')) {
		            $mapping["sensor_read_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'io_ops'
		            ];
		        } elseif (Str::contains($lowerKey, 'writes_per_sec')) {
		            $mapping["sensor_write_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'io_ops'
		            ];
		        } elseif (Str::contains($lowerKey, 'read_bytes_per_sec')) {
		            $mapping["sensor_read_bandwidth_mbps"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['multiply' => 8, 'divide' => 1000000],
		                'sensor_class' => 'bandwidth'
		            ];
		        } elseif (Str::contains($lowerKey, 'write_bytes_per_sec')) {
		            $mapping["sensor_write_bandwidth_mbps"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['multiply' => 8, 'divide' => 1000000],
		                'sensor_class' => 'bandwidth'
		            ];
		        } elseif (Str::contains($lowerKey, 'usec_per_read_op')) {
		            $mapping["sensor_read_latency_ms"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['divide' => 1000],
		                'sensor_class' => 'latency'
		            ];
		        } elseif (Str::contains($lowerKey, 'usec_per_write_op')) {
		            $mapping["sensor_write_latency_ms"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['divide' => 1000],
		                'sensor_class' => 'latency'
		            ];
		        } elseif (Str::contains($lowerKey, 'queue_depth')) {
		            $mapping["sensor_queue_depth"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'other'
		            ];
		        }

		        // --- NETWORK INTERFACE PERFORMANCE (Ports Statistics) ---
		        elseif (Str::contains($lowerKey, 'received_bytes_per_sec')) {
		            $mapping["ports_stat_ifInOctets_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInOctets_rate',
		                'conversion' => ['multiply' => 8]
		            ];
		        } elseif (Str::contains($lowerKey, 'transmitted_bytes_per_sec')) {
		            $mapping["ports_stat_ifOutOctets_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifOutOctets_rate',
		                'conversion' => ['multiply' => 8]
		            ];
		        } elseif (Str::contains($lowerKey, 'received_packets_per_sec')) {
		            $mapping["ports_stat_ifInUcastPkts_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInUcastPkts_rate',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'transmitted_packets_per_sec')) {
		            $mapping["ports_stat_ifOutUcastPkts_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifOutUcastPkts_rate',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'total_errors_per_sec') || Str::contains($lowerKey, 'crc_errors_per_sec')) {
		            $mapping["ports_stat_ifInErrors_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifInErrors_rate',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'dropped_errors_per_sec')) {
		            $mapping["ports_stat_ifOutDiscards_rate"] = [
		                'source_field' => $key,
		                'target_field' => 'ifOutDiscards_rate',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- HARDWARE / ENVIRONMENTAL SENSORS (Dynamic) ---
		        elseif (Str::contains($lowerKey, '_temperature_measurement_')) {
		            $mapping["sensor_temperature_celsius"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'temperature'
		            ];
		        } elseif (Str::contains($lowerKey, '_voltage_measurement_')) {
		            $mapping["sensor_voltage_volts"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'voltage'
		            ];
		        } elseif (Str::contains($lowerKey, '_power_measurement_') || Str::contains($lowerKey, 'tx_bias_measurement')) {
		            $mapping["sensor_optical_power_dbm"] = [
		                'source_field' => $key,
		                'target_field' => 'sensor_current',
		                'conversion' => ['none' => true],
		                'sensor_class' => 'power'
		            ];
		        } elseif (Str::contains($lowerKey, '_status_')) {
		            // Status goes to a generic component table or is handled by LibreNMS status translation
		            $mapping["pure_metrics_status"] = [
		                'source_field' => $key,
		                'target_field' => 'status',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- HARDWARE / ENTITY / TRANSCEIVER (Static) ---
		        elseif (Str::contains($lowerKey, 'static_vendor_name')) {
		            $mapping["transceiver_vendor"] = [
		                'source_field' => $key,
		                'target_field' => 'vendor',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'static_serial')) {
		            $mapping["transceiver_serial"] = [
		                'source_field' => $key,
		                'target_field' => 'serial',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'static_wavelength')) {
		            $mapping["transceiver_wavelength"] = [
		                'source_field' => $key,
		                'target_field' => 'wavelength',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'static_link_length')) {
		            $mapping["transceiver_distance"] = [
		                'source_field' => $key,
		                'target_field' => 'distance',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'static_identifier')) {
		            $mapping["transceiver_type"] = [
		                'source_field' => $key,
		                'target_field' => 'type',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'serial')) {
		            $mapping["hw_serial"] = [ // Non-transceiver serials
		                'source_field' => $key,
		                'target_field' => 'entPhysicalSerialNum',
		                'conversion' => ['none' => true]
		            ];
		        }

		        // --- QoS and Fallback Metrics (Custom) ---
		        elseif (Str::contains($lowerKey, 'iops_limit')) {
		            $mapping["qos_limit_iops"] = [
		                'source_field' => $key,
		                'target_field' => 'qos_iops_limit',
		                'conversion' => ['none' => true]
		            ];
		        } elseif (Str::contains($lowerKey, 'bandwidth_limit')) {
		            $mapping["qos_limit_bandwidth_mb"] = [
		                'source_field' => $key,
		                'target_field' => 'qos_bandwidth_limit',
		                'conversion' => ['divide' => 1048576]
		            ];
		        }

		        // --- FALLBACK: Custom Metric ---
		        else {
		            $mapping["pure_metrics_{$resource}_{$lowerKey}"] = [
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
		                $unit = '�C';
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
