<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MetricFieldMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DataMatcher
{

		protected array $staticMap = [
		    'devices' => [
		        'status' => 'status',
		        'serial' => 'serial',
		        'serial_number' => 'serial',
		        'model' => 'hardware',
		        'hardware_model' => 'hardware',
		        'firmware_version' => 'version',
		        'firmware' => 'version',
		        'os_version' => 'version',
		        'version' => 'version',
		        'os' => 'os',
		        'uptime' => 'uptime',
		        'hostname' => 'hostname',
		        'sysname' => 'sysName',
		        'location' => 'location',
		        'contact' => 'sysContact',
		    ],
		    'sensors' => [
		        'temperature' => 'sensor_current',
		        'temp' => 'sensor_current',
		        'power' => 'sensor_current',
           	'power_consumption' => 'sensor_current',
		        'voltage' => 'sensor_current',
		        'volt' => 'sensor_current',
		        'current' => 'sensor_current',
		        'fan_speed' => 'sensor_current',
            'fanspeed' => 'sensor_current',
 		        'humidity' => 'sensor_current',
		        'latency' => 'sensor_current',
		        'iops' => 'sensor_current',
		        'reads_per_sec' => 'sensor_current',
		        'writes_per_sec' => 'sensor_current',
		        'data_reduction' => 'sensor_current',
		        'total_reduction' => 'sensor_current',
		        'drive_protocol' => 'sensor_current',
		        'bytes_per_op' => 'sensor_current',
		        'bytes_per_read' => 'sensor_current',
		        'bytes_per_write' => 'sensor_current',
		        'usec_per_read_op' => 'sensor_current',
		        'usec_per_write_op' => 'sensor_current',
		        'queue_usec_per_read_op' => 'sensor_current',
		        'queue_usec_per_write_op' => 'sensor_current',
		        'san_usec_per_read_op' => 'sensor_current',
		        'san_usec_per_write_op' => 'sensor_current',
		        'service_usec_per_read_op' => 'sensor_current',
		        'service_usec_per_write_op' => 'sensor_current',
		        'others_per_sec' => 'sensor_current',
		        'read_bytes_per_sec'    => 'sensor_current',
		        'write_bytes_per_sec'   => 'sensor_current',
		        'received_bytes_per_sec' => 'sensor_current',
		        'transmitted_bytes_per_sec' => 'sensor_current',
		        'received_packets_per_sec' => 'sensor_current',
		        'transmitted_packets_per_sec' => 'sensor_current',
		        'total_errors_per_sec' => 'sensor_current',
		        'drive_status' => 'sensor_status',
  	        'tmp' => 'sensor_current',

		    ],
		    'ports' => [
		        'interface_speed' 			=> 'ifSpeed',
		        'speed' 								=> 'ifSpeed',
		        'interface_status'			=> 'ifOperStatus',
		        'oper_status' 					=> 'ifOperStatus',
		        'admin_status' 					=> 'ifAdminStatus',
		        'interface_name' 				=> 'ifName',
		        'interface_alias' 			=> 'ifAlias',
		        'interface_description' => 'ifDescr',
		        'mtu' 									=> 'ifMtu',
		        'eth_mtu'							 	=> 'ifMtu',
		        'eth_address' 					=> 'ifPhysAddress',
		        'eth_mac_address' 			=> 'ifPhysAddress',
		        'eth_speed' 						=> 'ifSpeed',
		    ],
		    'storage' => [
				    'total_capacity'     => 'storage_size',
				    'volume_provisioned' => 'storage_size',
				    'used_capacity'      => 'storage_used',
				    'volume_used'        => 'storage_used',
				    'free_capacity'      => 'storage_free',
				    'volume_free'        => 'storage_free',
				    'drive_capacity'     => 'storage_size',
				    'drive_used'         => 'storage_used',
			      'total_used' 				 => 'storage_used',
			      'total_physical'   	 => 'storage_size',
			      'total_provisioned'  => 'storage_size',
		        'raw_capacity' 			 => 'storage_size',
		        'drive_type'				 => 'storage_type',

				],
		];

		protected array $sensorClassMap = [
					'temperature' 			=> 'temperature',
	    		'temp' 							=> 'temperature',
	    		'power' 						=> 'power',
        	'power_consumption' => 'power',
					'voltage' 					=> 'voltage',
	   		 	'volt' 							=> 'voltage',
	  	  	'current' 					=> 'current',
					'fan_speed' 				=> 'fanspeed',
	  	  	'fanspeed' 					=> 'fanspeed',
        	'ampere' 						=> 'current',
 					'fan' 							=> 'fanspeed',
	  	  	'humidity' 					=> 'humidity',
	  	  	'frequency' 				=> 'frequency',
					'signal' 						=> 'signal',
	  	  	'load' 							=> 'load',
	  	  	'state' 						=> 'state',
					'status' 						=> 'state',
	  	  	'iops' 							=> 'count',
	  	  	'reads_per_sec' 		=> 'count',
					'writes_per_sec'	  => 'count',
	  	  	'latency' 					=> 'delay',
	    		'delay' 						=> 'delay',
					'usec_per_op' 			=> 'delay',
	  	  	'reduction' 				=> 'ratio',
	    		'ratio' 						=> 'ratio',
					'capacity' 					=> 'count',
	  	  	'space' 						=> 'count',
	    		'nvb' 							=> 'state',
					'bay' 							=> 'state',
		    	'provisioned' 			=> 'count',
	 		   	'connections' 			=> 'count',
					'snapshots' 				=> 'count',
	    		'usec' 							=> 'delay',
					'sec' 							=> 'count',
					'tmp' 							=> 'temperature',
        	'reads' 						=> 'count',
        	'writes' 						=> 'count',
		];


    protected bool $verbose = false;
		protected int $matchedCount = 0;
		protected int $unmatchedCount = 0;
		protected int $errorCount = 0;

		protected function logDebug(string $message): void
		{
			if ($this->verbose) {
		        Log::debug($message);
		    }
		}

    public function processDeviceMetrics(Device $device): array
    {
        $this->matchedCount = 0;
        $this->unmatchedCount = 0;
        $this->errorCount = 0;

        $metrics = DB::table('device_api_metrics')
            ->where('device_id', $device->device_id)
            ->whereNull('matched_at')
            ->get();

        if ($metrics->isEmpty()) {
            return $this->getStats();
        }

        Log::debug("Processing {$metrics->count()} unmatched metrics for device {$device->hostname}");

        foreach ($metrics as $metric) {
            try {
                $mapping = $this->matchMetric($metric, $device);

                if ($mapping && !$mapping->isUnmatched()) {
                    $this->storeMetricValue($metric, $mapping, $device);
                    $this->markAsMatched($metric, $mapping);
                    $this->matchedCount++;
                } else {
                    $this->unmatchedCount++;
                }
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error("Error processing metric {$metric->metric_name} for device {$device->hostname}: {$e->getMessage()}");
            }
        }

				return [
				    'device' => $device->hostname,
				    'matched' => $this->matchedCount,
				    'unmatched' => $this->unmatchedCount,
				    'errors' => $this->errorCount,
				];

        if ($this->matchedCount > 0 || $this->unmatchedCount > 0) { Log::info(...); } return [ 'device' => $device->hostname, 'matched' => $this->matchedCount, 'unmatched' => $this->unmatchedCount, 'errors' => $this->errorCount, ];

        return $this->getStats();
    }

    protected function matchMetric($metric, Device $device): ?MetricFieldMapping
    {
        $metricName = strtolower($metric->metric_name ?? '');
        $resourceType = strtolower($metric->resource_type ?? 'unknown');

        // Get device vendor/os - handle missing columns
        $deviceVendor = $device->vendor ?? null;
        $deviceOs = $device->os ?? null;

        // Debug logging
        Log::debug("Matching metric: {$metricName}, resource_type: {$resourceType}, device vendor: {$deviceVendor}, os: {$deviceOs}");

        // Step 1: Try static mapping first
        $staticMapping = $this->findStaticMapping($metricName);
        if ($staticMapping) {
            Log::debug("Found static mapping for {$metricName}: {$staticMapping['table']}.{$staticMapping['field']}");
            return $this->createOrUpdateMapping(
                $metricName,
                $resourceType,
                $device,
                $staticMapping['table'],
                $staticMapping['field'],
                false // Not auto-learned since it's from static map
            );
        }

        // Step 2: Try dynamic mapping from database
        $mapping = MetricFieldMapping::where('metric_name', $metricName)
            ->where(function ($q) use ($resourceType) {
                $q->where('resource_type', $resourceType)
                  ->orWhereNull('resource_type')
                  ->orWhere('resource_type', 'generic');
            })
            ->where(function ($q) use ($deviceVendor) {
                $q->where('vendor', $deviceVendor)
                  ->orWhereNull('vendor');
            })
            ->where(function ($q) use ($deviceOs) {
                $q->where('os', $deviceOs)
                  ->orWhereNull('os');
            })
            ->where('enabled', true)
            ->orderByRaw('vendor IS NULL ASC, os IS NULL ASC') // Prefer specific over generic
            ->first();

        if ($mapping) {
            Log::debug("Found dynamic mapping for {$metricName}: {$mapping->librenms_table}.{$mapping->librenms_field}");
        } else {
            Log::debug("No dynamic mapping found for {$metricName} with resource_type: {$resourceType}");
        }

        if ($mapping && !$mapping->isUnmatched()) {
            return $mapping;
        }

        // Step 3: No match found - create placeholder for admin review
        Log::debug("Creating placeholder for unmatched metric: {$metricName}");
        return $this->createPlaceholderMapping($metric, $device);
    }

    protected function storeMetricValue($metric, MetricFieldMapping $mapping, Device $device): void
    {
        $value = $metric->value ?? $metric->string_value;

        // Transform value based on mapping rules
        // NOTE: $mapping->transformValue() is assumed to exist on MetricFieldMapping model
        $transformedValue = $mapping->transformValue($value);

        if ($transformedValue === null) {
            Log::debug("Skipping null value for metric {$metric->metric_name}");
            return;
        }


        try {
            // Special handling for sensors table
            if ($mapping->librenms_table === 'sensors') {
                $this->updateSensor($device, $metric, $mapping, $transformedValue);
                return;
            }

            // Special handling for ports table
            if ($mapping->librenms_table === 'ports') {
                $this->updatePort($device, $metric, $mapping, $transformedValue);
                return;
            }

            // Special handling for storage table
            if ($mapping->librenms_table === 'storage') {
                $this->updateStorage($device, $metric, $mapping, $transformedValue);
                return;
            }

            // Standard table update (e.g., 'devices')
            $this->updateStandardTable($device, $mapping, $transformedValue);

        } catch (\Exception $e) {
            Log::error("Failed to store metric {$metric->metric_name} in {$mapping->librenms_table}.{$mapping->librenms_field}: {$e->getMessage()}");
            throw $e;
        }
    }

//    protected function storeResourceMetrics(RestApiEndpoint $endpoint, array $item, string $resourceType, int $connectionId)
//		{
//			    $resourceId = data_get($item, $endpoint->resource_id_path ?? 'id');
//			    $resourceName = data_get($item, $endpoint->resource_name_path ?? 'name');
//
//			    if (!$resourceId && !$resourceName) {
//			        Log::warning("No resource ID or name found for item in endpoint {$endpoint->name}");
//			        return;
//			    }
//
//			    $resourceId = $resourceId ?? $resourceName;
//			    $resourceName = $resourceName ?? $resourceId;
//
//			    $collectedAt = Carbon::now();
//
//			    $existingMetrics = DB::table('device_api_metrics')
//			        ->where('device_id', $this->device->device_id)
//			        ->where('api_endpoint_id', $endpoint->id)
//			        ->where('resource_id', $resourceId)
//			        ->get()
//			        ->keyBy('metric_name');
//
//			    $metricsToInsert = [];
//			    $metricsToUpdate = [];
//			    $processedMetricNames = [];
//
//			    foreach ($endpoint->metric_map as $metricName => $apiPath) {
//			        try {
//			            $value = data_get($item, $apiPath);
//			            $processedMetricNames[] = $metricName;
//
//			            if ($value === null) {
//			                continue;
//			            }
//
//			            $isNumeric = is_numeric($value);
//			            $numericValue = $isNumeric ? (float)$value : null;
//			            $stringValue = null;
//
//			            if (!$isNumeric) {
//			                if (is_array($value) || is_object($value)) {
//			                    $stringValue = json_encode($value);
//			                } else {
//			                    $stringValue = (string)$value;
//			                }
//			            }
//
//			            if (isset($existingMetrics[$metricName])) {
//			                $existing = $existingMetrics[$metricName];
//			                $valueChanged = false;
//
//			                if ($isNumeric) {
//			                    $valueChanged = abs($existing->value - $numericValue) > 0.0001;
//			                } else {
//			                    $valueChanged = $existing->string_value !== $stringValue;
//			                }
//
//			                if ($valueChanged) {
//			                    $metricsToUpdate[] = [
//			                        'id' => $existing->id,
//			                        'value' => $numericValue,
//			                        'string_value' => $stringValue,
//			                        'collected_at' => $collectedAt,
//			                        'updated_at' => $collectedAt,
//			                    ];
//
//			                    Log::debug("Metric {$metricName} changed from " . ($existing->value ?? $existing->string_value) . " to " . ($numericValue ?? $stringValue) . " for resource {$resourceName}");
//			                } else {
//			                    DB::table('device_api_metrics')
//			                        ->where('id', $existing->id)
//			                        ->update([
//			                            'collected_at' => $collectedAt,
//			                            'updated_at' => $collectedAt,
//			                        ]);
//			                }
//			            } else {
//			                $metricsToInsert[] = [
//			                    'device_id' => $this->device->device_id,
//			                    'api_endpoint_id' => $endpoint->id,
//			                    'api_connection_id' => $connectionId,
//			                    'resource_type' => $resourceType,
//			                    'resource_id' => $resourceId,
//			                    'resource_name' => $resourceName,
//			                    'metric_name' => $metricName,
//			                    'metric_type' => 'gauge',
//			                    'value' => $numericValue,
//			                    'string_value' => $stringValue,
//			                    'raw_response' => null,
//			                    'collected_at' => $collectedAt,
//			                    'created_at' => $collectedAt,
//			                    'updated_at' => $collectedAt,
//			                ];
//			                Log::debug("New metric {$metricName} = " . ($numericValue ?? $stringValue) . " for resource {$resourceName}");
//			            }
//
//			        } catch (\Exception $e) {
//			            Log::error("Error processing metric {$metricName}: " . $e->getMessage());
//			        }
//			    }
//
//			    // Delete obsolete metrics
//			    $metricsToDelete = $existingMetrics->keys()->diff($processedMetricNames);
//			    if ($metricsToDelete->isNotEmpty()) {
//			        DB::table('device_api_metrics')
//			            ->where('device_id', $this->device->device_id)
//			            ->where('api_endpoint_id', $endpoint->id)
//			            ->where('resource_id', $resourceId)
//			            ->whereIn('metric_name', $metricsToDelete->toArray())
//			            ->delete();
//			        Log::info("Deleted " . $metricsToDelete->count() . " obsolete metrics for {$resourceType} '{$resourceName}'");
//			    }
//
//			    // Batch insert new metrics
//			    if (!empty($metricsToInsert)) {
//			        try {
//			            DB::table('device_api_metrics')->insert($metricsToInsert);
//			            Log::info("Inserted " . count($metricsToInsert) . " new metrics for {$resourceType} '{$resourceName}'");
//			        } catch (\Exception $e) {
//			            Log::error("Failed to insert metrics for resource {$resourceName}: " . $e->getMessage());
//			        }
//			    }
//
//			    // Batch update changed metrics
//			    if (!empty($metricsToUpdate)) {
//			        try {
//			            foreach ($metricsToUpdate as $metric) {
//			                DB::table('device_api_metrics')
//			                    ->where('id', $metric['id'])
//			                    ->update([
//			                        'value' => $metric['value'],
//			                        'string_value' => $metric['string_value'],
//			                        'collected_at' => $metric['collected_at'],
//			                        'updated_at' => $metric['updated_at'],
//			                    ]);
//			            }
//			            Log::info("Updated " . count($metricsToUpdate) . " changed metrics for {$resourceType} '{$resourceName}'");
//			        } catch (\Exception $e) {
//			            Log::error("Failed to update metrics for resource {$resourceName}: " . $e->getMessage());
//			        }
//			    }
//		}

    protected function updateSensor(Device $device, $metric, MetricFieldMapping $mapping, $value): void
		{
		    // Determine the sensor class (e.g., 'temperature', 'fanspeed', 'delay')
		    $sensorClass = $this->determineSensorClass($metric->metric_name);
		    $sensorIndex = $metric->resource_id ?? md5($metric->metric_name); // Use resource_id as index

		    // Try to find an existing sensor
		    $sensor = DB::table('sensors')
		        ->where('device_id', $device->device_id)
		        ->where('sensor_class', $sensorClass)
		        ->where('sensor_type', 'rest-api') // Use a consistent type for API polls
		        ->where('sensor_index', $sensorIndex)
		        ->first();

		    // Prepare data payload
		    $data = [
		        'sensor_current' => is_numeric($value) ? (float) $value : 0,
		        'lastupdate' => now(),
		    ];

		    if ($sensor) {
		        // Update existing sensor
		        DB::table('sensors')
		            ->where('sensor_id', $sensor->sensor_id)
		            ->update($data);

		        Log::debug("Updated sensor {$sensorClass} ({$sensorIndex}) = {$value} for device {$device->hostname}");
		    } else {
		        // Insert new sensor (Creation)
		        DB::table('sensors')->insert(array_merge($data, [
		            'device_id' => $device->device_id,
		            'sensor_class' => $sensorClass,
		            'sensor_type' => 'rest-api',
		            'sensor_index' => $sensorIndex,
		            'sensor_descr' => $metric->resource_name ?? $metric->metric_name,
		            'sensor_oid' => '', // API sensors don't use OID
		            'poller_type' => 'rest-api',
		            'created_at' => now(),
		        ]));

		        Log::info("Created new sensor {$sensorClass} ({$sensorIndex}) for device {$device->hostname}");
		    }
		}

		protected function determineSensorClass(string $metricName): string
		{
		    $metricName = str_replace(['-', ' '], '_', strtolower($metricName));

		    if (str_contains($metricName, 'temp') || str_contains($metricName, 'thermal') || $metricName === 'tmp') {
		        return 'temperature';
		    }
		    if (str_contains($metricName, 'volt')) {
		        return 'voltage';
		    }
		    if (str_contains($metricName, 'power') || str_contains($metricName, 'watt')) {
		        return 'power';
		    }
		    if (str_contains($metricName, 'fan') || str_contains($metricName, 'rpm')) {
		        return 'fanspeed';
		    }
		    if (str_contains($metricName, 'usec_per') || str_contains($metricName, 'latency')) {
		        return 'delay';
		    }
		    if (str_contains($metricName, 'reads_per_sec') || str_contains($metricName, 'writes_per_sec')) {
		        return 'count';
		    }

		    return 'state';
		}

		protected function updatePort(Device $device, $metric, MetricFieldMapping $mapping, $value): void
		{
		    $name = $this->normalizeResourceName($metric->resource_name);
		    $id = $this->normalizeResourceName($metric->resource_id);

		    // Look for the port by ifName, ifDescr, or ifAlias
		    $port = DB::table('ports')
		        ->where('device_id', $device->device_id)
		        ->where(function ($q) use ($name, $id) {
		            $q->where(DB::raw('LOWER(REPLACE(REPLACE(ifName, " ", ""), "_", ""))'), $name)
		              ->orWhere(DB::raw('LOWER(REPLACE(REPLACE(ifDescr, " ", ""), "_", ""))'), $name)
		              ->orWhere(DB::raw('LOWER(REPLACE(REPLACE(ifAlias, " ", ""), "_", ""))'), $name)
		              ->orWhere(DB::raw('LOWER(REPLACE(REPLACE(ifName, " ", ""), "_", ""))'), $id);
		        })
		        ->first();

		    if ($port) {
		        // If port is found, update the specific field
		        DB::table('ports')
		            ->where('port_id', $port->port_id)
		            ->update([
		                $mapping->librenms_field => $value,
		                'poll_time' => time(),
		            ]);

		        Log::debug("Updated port {$port->ifName} {$mapping->librenms_field} = {$value}");
		    } else {
		        // If the port entity doesn't exist, this metric cannot be committed.
		        // The entity creation step (from the device poller logic) is required first.
		        Log::warning("No matching port found for {$metric->metric_name} (resource: {$metric->resource_name}) on {$device->hostname}");
		    }
		}

    protected function updateStorage(Device $device, $metric, MetricFieldMapping $mapping, $value): void
		{
		    // Try to find storage by resource_id or resource_name
		    $storage = DB::table('storage')
		        ->where('device_id', $device->device_id)
		        ->where(function ($q) use ($metric) {
		            $q->where('storage_descr', $metric->resource_name)
		              ->orWhere('storage_descr', $metric->resource_id)
		              ->orWhere('storage_index', $metric->resource_id);
		        })
		        ->first();

		    if ($storage) {
		        $updateData = [
		            $mapping->librenms_field => $value,
		        ];

		        // If updating storage_used or storage_size, recalculate free space and percentage
		        $size = $mapping->librenms_field === 'storage_size' ? $value : $storage->storage_size;
		        $used = $mapping->librenms_field === 'storage_used' ? $value : $storage->storage_used;

		        $updateData['storage_free'] = max(0, $size - $used);
		        $updateData['storage_perc'] = $size > 0 ? round(($used / $size) * 100, 2) : 0;
		        $updateData['last_polled'] = now();

		        DB::table('storage')
		            ->where('storage_id', $storage->storage_id)
		            ->update($updateData);

		        Log::debug("Updated storage {$storage->storage_descr} {$mapping->librenms_field} = {$value}");
		    } else {
		        Log::warning("Storage not found for metric {$metric->metric_name} (resource: {$metric->resource_name}) on {$device->hostname}");
		    }
		}

    protected function updateStandardTable(Device $device, MetricFieldMapping $mapping, $value): void
    {
        $updated = DB::table($mapping->librenms_table)
            ->where('device_id', $device->device_id)
            ->update([
                $mapping->librenms_field => $value,
            ]);

        if ($updated) {
            Log::debug("Updated {$mapping->librenms_table}.{$mapping->librenms_field} = {$value} for device {$device->hostname}");
        } else {
            Log::warning("No rows updated for {$mapping->librenms_table}.{$mapping->librenms_field} on device {$device->hostname}");
        }
    }


    protected function normalizeResourceName(?string $name): ?string
		{
		    return $name ? strtolower(str_replace([' ', '_', '-'], '', $name)) : null;
		}

    protected function generateSensorIndex($metric): string
    {
        return 'api-' . ($metric->resource_id ?? md5($metric->metric_name));
    }

    protected function markAsMatched($metric, MetricFieldMapping $mapping): void
    {
        DB::table('device_api_metrics')
            ->where('id', $metric->id)
            ->update([
                'matched_at' => now(),
                'mapping_id' => $mapping->id,
            ]);
    }

    protected function createOrUpdateMapping(
        string $metricName,
        string $resourceType,
        Device $device,
        string $table,
        string $field,
        bool $autoLearned = true
    ): MetricFieldMapping {
        $mapping = MetricFieldMapping::updateOrCreate(
            [
                'metric_name' => $metricName,
                'resource_type' => $resourceType,
                'vendor' => $device->vendor ?? null,
                'os' => $device->os ?? null,
            ],
            [
                'librenms_table' => $table,
                'librenms_field' => $field,
                'auto_learned' => $autoLearned,
                'last_seen_at' => now(),
                'last_matched_device_id' => $device->device_id,
                'enabled' => true,
            ]
        );

        return $mapping;
    }

		protected function createPlaceholderMapping($metric, $device): MetricFieldMapping
		{
		    return MetricFieldMapping::updateOrCreate(
		        [
		            'metric_name' => $metric->metric_name,
		            'resource_type' => $metric->resource_type,
		            'vendor' => $device->vendor ?? null,
		            'os' => $device->os ?? null,
		        ],
		        [

		            'librenms_table' => '',
		            'librenms_field' => '',

		            'enabled' => false,
		            'auto_learned' => true,
		            'last_seen_at' => now(),
		            'last_matched_device_id' => $device->device_id,
		        ]
		    );
		}


    protected function getStats(): array
    {
        return [
            'matched' => $this->matchedCount,
            'unmatched' => $this->unmatchedCount,
            'errors' => $this->errorCount,
        ];
    }

    public function resetMetrics(Device $device, ?string $metricName = null): int
    {
        $query = DB::table('device_api_metrics')
            ->where('device_id', $device->device_id)
            ->whereNotNull('matched_at');

        if ($metricName) {
            $query->where('metric_name', $metricName);
        }

        return $query->update([
            'matched_at' => null,
            'mapping_id' => null,
        ]);
    }

    protected function findStaticMapping(string $metricName): ?array
		{
		    $normalized = str_replace(['-', ' '], '_', strtolower($metricName));

		    // Try DB first
		    $dbMapping = MetricFieldMapping::where('metric_name', $normalized)
		        ->where('enabled', true)
		        ->first();

		    if ($dbMapping) {
		        return [
		            'table' => $dbMapping->librenms_table,
		            'field' => $dbMapping->librenms_field,
		        ];
		    }

		    // Fallback to JSON config
		    $path = storage_path('app/resource_mappings.json');
		    if (!file_exists($path)) return null;

		    $json = json_decode(file_get_contents($path), true);
		    foreach ($json['metric_field_mappings'] ?? [] as $map) {
		        if (strtolower($map['metric_name']) === $normalized) {
		            return [
		                'table' => $map['librenms_table'],
		                'field' => $map['librenms_field'],
		            ];
		        }
		    }

		    return null;
		}
}
