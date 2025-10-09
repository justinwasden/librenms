<?php

namespace App\RestApi\Metrics;

use App\Models\Device;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\RRD\RrdDefinition;
use Log;

class MetricsStager
{
    protected Device $device;
    
    public function __construct(Device $device)
    {
        $this->device = $device;
    }
    
    /**
     * Stage metrics for storage
     * 
     * @param array $metrics Flattened metrics array
     * @param bool $isPoller Whether this is being called from poller (stores RRD)
     */
    public function stageMetrics(array $metrics, bool $isPoller = false)
    {
        foreach ($metrics as $key => $value) {
            // Skip non-numeric values for RRD storage
            if ($isPoller && is_numeric($value)) {
                $this->storeRrdData($key, $value);
            }
            
            // Always log the metric for debugging
            Log::debug("Staged metric for {$this->device->hostname}: {$key} = {$value}");
        }
    }
    
    /**
     * Store metric in RRD file
     * 
     * @param string $key Metric key
     * @param mixed $value Metric value
     */
    protected function storeRrdData(string $key, $value)
    {
        // Sanitize key for RRD filename
        $sanitizedKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        
        try {
            // Define RRD dataset
            $rrd_def = RrdDefinition::make()
                ->addDataset($sanitizedKey, 'GAUGE', 0, 125000000000);
            
            // Get datastore instance
            $datastore = app('Datastore');
            
            // Store the data
            $datastore->put(
                ['device_id' => $this->device->device_id],
                "rest_api_{$sanitizedKey}",
                [
                    'rrd_def' => $rrd_def,
                    'rrd_name' => ['rest_api', $sanitizedKey],
                ],
                $value
            );
            
            Log::debug("Stored RRD data for {$this->device->hostname}: {$sanitizedKey} = {$value}");
        } catch (\Exception $e) {
            Log::error("Failed to store RRD data for {$this->device->hostname}: {$e->getMessage()}");
        }
    }
}
