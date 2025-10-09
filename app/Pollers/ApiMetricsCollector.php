<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiMetric;
use Carbon\Carbon;
use Log;

class ApiMetricsCollector
{
    protected Device $device;
    
    public function __construct(Device $device)
    {
        $this->device = $device;
    }
    
    /**
     * Store discovered metrics in the database
     * 
     * @param string $resourceType Type of resource (device, port, sensor, etc.)
     * @param string $endpointName Name of the endpoint
     * @param array $metrics Flattened metrics array
     */
    public function storeMetric(string $resourceType, string $endpointName, array $metrics)
    {
        foreach ($metrics as $key => $value) {
            try {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $this->device->device_id,
                        'endpoint_name' => $endpointName,
                        'metric_key' => $key,
                    ],
                    [
                        'metric_value' => is_array($value) ? json_encode($value) : (string) $value,
                        'resource_type' => $resourceType,
                        'last_updated' => Carbon::now(),
                    ]
                );
                
                Log::debug("Stored metric for {$this->device->hostname}: {$endpointName}.{$key} = " . 
                    (is_array($value) ? json_encode($value) : $value));
            } catch (\Exception $e) {
                Log::error("Failed to store metric for {$this->device->hostname}: {$e->getMessage()}");
            }
        }
    }
    
    /**
     * Retrieve all metrics for this device
     * 
     * @param string|null $resourceType Filter by resource type
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMetrics(?string $resourceType = null)
    {
        $query = RestApiMetric::where('device_id', $this->device->device_id);
        
        if ($resourceType) {
            $query->where('resource_type', $resourceType);
        }
        
        return $query->get();
    }
    
    /**
     * Clean up old metrics (optional maintenance)
     * 
     * @param int $daysOld Delete metrics older than this many days
     * @return int Number of deleted metrics
     */
    public function cleanupOldMetrics(int $daysOld = 30): int
    {
        $cutoff = Carbon::now()->subDays($daysOld);
        
        return RestApiMetric::where('device_id', $this->device->device_id)
            ->where('last_updated', '<', $cutoff)
            ->delete();
    }
}
