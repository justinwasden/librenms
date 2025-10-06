<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MetricFieldMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
		        'raw_capacity' => 'storage_total',
		    ],
		    'sensors' => [
		        'temperature' => 'sensor_current',
		        'temp' => 'sensor_current',
		        'power' => 'sensor_current',
		        'voltage' => 'sensor_current',
		        'volt' => 'sensor_current',
		        'current' => 'sensor_current',
		        'fan_speed' => 'sensor_current',
		        'humidity' => 'sensor_current',
		        'latency' => 'sensor_current',
		        'iops' => 'sensor_current',
		        'reads_per_sec' => 'sensor_current',
		        'writes_per_sec' => 'sensor_current',
		        'data_reduction' => 'sensor_current',
		        'total_reduction' => 'sensor_current',
		        'usec_per_read_op' => 'sensor_current',
		        'usec_per_write_op' => 'sensor_current',
		        'read_bytes_per_sec'    => 'sensor_current',
		        'write_bytes_per_sec'   => 'sensor_current',
		        'total_physical'        => 'sensor_current',
		        'total_provisioned'     => 'sensor_current',
		        'drive_status' => 'sensor_current',
		        'drive_capacity' => 'sensor_current',
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
		        'received_bytes_per_sec' => 'sensor_current',
		        'transmitted_bytes_per_sec' => 'sensor_current',
		        'received_packets_per_sec' => 'sensor_current',
		        'transmitted_packets_per_sec' => 'sensor_current',
		        'total_errors_per_sec' => 'sensor_current',
		        'total_used' => 'sensor_current',
            'tmp' => 'temperature',

		    ],
		    'ports' => [
		        'interface_speed' => 'ifSpeed',
		        'speed' => 'ifSpeed',
		        'interface_status' => 'ifOperStatus',
		        'oper_status' => 'ifOperStatus',
		        'admin_status' => 'ifAdminStatus',
		        'interface_name' => 'ifName',
		        'interface_alias' => 'ifAlias',
		        'interface_description' => 'ifDescr',
		        'mtu' => 'ifMtu',
		        'eth_mtu' => 'ifMtu',
		        'eth_address' => 'ifPhysAddress',
		        'eth_mac_address' => 'ifPhysAddress',
		        'eth_speed' => 'ifSpeed',
		    ],
		    'storage' => [
		        'total_capacity' => 'storage_size',
		        'volume_provisioned' => 'storage_size',
		        'used_capacity' => 'storage_used',
		        'volume_used' => 'storage_used',
		        'free_capacity' => 'storage_free',
		    ],
		];

		    /**
		     * Sensor class mappings based on metric name patterns
		     */
		protected array $sensorClassMap = [
		    'temperature' => 'temperature',
		    'temp' => 'temperature',
		    'power' => 'power',
		    'voltage' => 'voltage',
		    'volt' => 'voltage',
		    'current' => 'current',
		    'fan_speed' => 'fanspeed',
		    'fan' => 'fanspeed',
		    'humidity' => 'humidity',
		    'frequency' => 'frequency',
		    'signal' => 'signal',
		    'load' => 'load',
		    'state' => 'state',
		    'status' => 'state',
		    'iops' => 'count',
		    'reads_per_sec' => 'count',
		    'writes_per_sec' => 'count',
		    'latency' => 'delay',
		    'usec_per_op' => 'delay',
		    'reduction' => 'ratio',
		    'ratio' => 'ratio',
		    'capacity' => 'storage',
		    'space' => 'storage',
		    'connections' => 'count',
		    'snapshots' => 'count',
		    'tmp' => 'temperature',
		];


    protected int $matchedCount = 0;
    protected int $unmatchedCount = 0;
    protected int $errorCount = 0;

    /**
     * Process all unmatched metrics for a device
     */
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
                    $this->matchedCount++;  // <-- This increments to 70
                } else {
                    $this->unmatchedCount++;
                }
            } catch (\Exception $e) {

                $this->errorCount++;
                Log::error("Error processing metric {$metric->metric_name} for device {$device->hostname}: {$e->getMessage()}");
            }
        }

        if ($this->matchedCount > 0 || $this->unmatchedCount > 0) {
            Log::info("DataMatcher for device {$device->hostname}: {$this->matchedCount} matched, {$this->unmatchedCount} unmatched, {$this->errorCount} errors");
        }

        return $this->getStats();
    }

    /**
     * Find the best mapping for a metric
     */
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

        // Step 2: Try dynamic mapping from database - SIMPLIFIED
        // First try exact match with vendor/os
        $mapping = MetricFieldMapping::where('metric_name', $metricName)
            ->where(function ($q) use ($resourceType) {
                $q->where('resource_type', $resourceType)
                  ->orWhereNull('resource_type');
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

    /**
     * Find a static mapping for a metric
     */
    protected function findStaticMapping(string $metricName): ?array
    {
        foreach ($this->staticMap as $table => $fields) {
            if (isset($fields[$metricName])) {
                return [
                    'table' => $table,
                    'field' => $fields[$metricName],
                ];
            }
        }

        return null;
    }

    /**
     * Store metric value in the appropriate LibreNMS table
     */
    protected function storeMetricValue($metric, MetricFieldMapping $mapping, Device $device): void
    {
        $value = $metric->value ?? $metric->string_value;

        // Transform value based on mapping rules
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

            // Standard table update
            $this->updateStandardTable($device, $mapping, $transformedValue);

        } catch (\Exception $e) {
            Log::error("Failed to store metric {$metric->metric_name} in {$mapping->librenms_table}.{$mapping->librenms_field}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Update or create a sensor record
     */
    protected function updateSensor(Device $device, $metric, MetricFieldMapping $mapping, $value): void
    {
        $sensorClass = $this->determineSensorClass($metric->metric_name);
        $sensorIndex = $this->generateSensorIndex($metric);

        $sensor = DB::table('sensors')
            ->where('device_id', $device->device_id)
            ->where('sensor_class', $sensorClass)
            ->where('sensor_type', 'rest-api')
            ->where('sensor_index', $sensorIndex)
            ->first();

        $data = [
            'sensor_current' => $value,
            'sensor_prev' => $sensor->sensor_current ?? null,
            'lastupdate' => now(),
        ];

        if ($sensor) {
            DB::table('sensors')
                ->where('sensor_id', $sensor->sensor_id)
                ->update($data);

            Log::debug("Updated sensor {$sensorClass} ({$sensorIndex}) = {$value} for device {$device->hostname}");
        } else {
            // Create new sensor
            DB::table('sensors')->insert(array_merge($data, [
                'device_id' => $device->device_id,
                'sensor_class' => $sensorClass,
                'sensor_type' => 'rest-api',
                'sensor_index' => $sensorIndex,
                'sensor_descr' => $metric->resource_name ?? $metric->metric_name,
                'sensor_oid' => '',
                'poller_type' => 'rest-api',
            ]));

            Log::info("Created new sensor {$sensorClass} ({$sensorIndex}) for device {$device->hostname}");
        }
    }

    /**
     * Update a port record
     */
    protected function updatePort(Device $device, $metric, MetricFieldMapping $mapping, $value): void
    {
        // Try to find port by resource_id or resource_name
        $port = DB::table('ports')
            ->where('device_id', $device->device_id)
            ->where(function ($q) use ($metric) {
                $q->where('ifName', $metric->resource_id)
                  ->orWhere('ifName', $metric->resource_name)
                  ->orWhere('ifDescr', $metric->resource_name)
                  ->orWhere('ifAlias', $metric->resource_name);
            })
            ->first();

        if ($port) {
            DB::table('ports')
                ->where('port_id', $port->port_id)
                ->update([
                    $mapping->librenms_field => $value,
                    'poll_time' => time(),
                ]);

            Log::debug("Updated port {$port->ifName} {$mapping->librenms_field} = {$value}");
        } else {
            Log::warning("Port not found for metric {$metric->metric_name} (resource: {$metric->resource_id})");
        }
    }

    /**
     * Update a storage record
     */
    protected function updateStorage(Device $device, $metric, MetricFieldMapping $mapping, $value): void
    {
        // Try to find storage by resource_id or resource_name
        $storage = DB::table('storage')
            ->where('device_id', $device->device_id)
            ->where(function ($q) use ($metric) {
                $q->where('storage_descr', $metric->resource_name)
                  ->orWhere('storage_descr', $metric->resource_id);
            })
            ->first();

        if ($storage) {
            $updateData = [
                $mapping->librenms_field => $value,
            ];

            // If updating storage_used or storage_size, recalculate percentage
            if (in_array($mapping->librenms_field, ['storage_used', 'storage_size'])) {
                $size = $mapping->librenms_field === 'storage_size' ? $value : $storage->storage_size;
                $used = $mapping->librenms_field === 'storage_used' ? $value : $storage->storage_used;
                $updateData['storage_free'] = $size - $used;
                $updateData['storage_perc'] = $size > 0 ? round(($used / $size) * 100, 2) : 0;
            }

            DB::table('storage')
                ->where('storage_id', $storage->storage_id)
                ->update($updateData);

            Log::debug("Updated storage {$storage->storage_descr} {$mapping->librenms_field} = {$value}");
        } else {
            Log::warning("Storage not found for metric {$metric->metric_name} (resource: {$metric->resource_name})");
        }
    }

    /**
     * Update a standard LibreNMS table
     */
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

    /**
     * Determine sensor class from metric name
     */
    protected function determineSensorClass(string $metricName): string
    {
        $metricName = strtolower($metricName);

        foreach ($this->sensorClassMap as $keyword => $class) {
            if (str_contains($metricName, $keyword)) {
                return $class;
            }
        }

        return 'state'; // Default fallback
    }

    /**
     * Generate a unique sensor index from metric
     */
    protected function generateSensorIndex($metric): string
    {
        return 'api-' . ($metric->resource_id ?? md5($metric->metric_name));
    }

    /**
     * Mark metric as matched
     */
    protected function markAsMatched($metric, MetricFieldMapping $mapping): void
    {
        DB::table('device_api_metrics')
            ->where('id', $metric->id)
            ->update([
                'matched_at' => now(),
                'mapping_id' => $mapping->id,
            ]);
    }

    /**
     * Create or update a mapping
     */
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

    /**
     * Create a placeholder mapping for unmatched metrics
     */
    protected function createPlaceholderMapping($metric, Device $device): MetricFieldMapping
    {
        $mapping = MetricFieldMapping::firstOrCreate(
            [
                'metric_name' => strtolower($metric->metric_name),
                'resource_type' => strtolower($metric->resource_type),
                'vendor' => $device->vendor ?? null,
                'os' => $device->os ?? null,
            ],
            [
                'librenms_table' => 'unmatched',
                'librenms_field' => 'unmatched',
                'auto_learned' => true,
                'last_seen_at' => now(),
                'last_matched_device_id' => $device->device_id,
                'enabled' => false, // Disabled until admin configures it
            ]
        );

        // Update last_seen_at even if mapping already exists
        $mapping->update([
            'last_seen_at' => now(),
            'last_matched_device_id' => $device->device_id,
        ]);

        return $mapping;
    }

    /**
     * Get processing statistics
     */
    protected function getStats(): array
    {
        return [
            'matched' => $this->matchedCount,
            'unmatched' => $this->unmatchedCount,
            'errors' => $this->errorCount,
        ];
    }

    /**
     * Reset unmatched metrics (for re-processing)
     */
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
}
