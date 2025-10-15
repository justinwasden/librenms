<?php

namespace App\RestApi\Metrics;

use App\Models\Device;
use App\RestApi\Data\DataRouter;
use Log;

class MetricsStager
{
    protected Device $device;
    protected DataRouter $router;
    
    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->router = new DataRouter($device);
    }
    
    /**
     * Stage metrics for storage using intelligent routing
     * 
     * @param array $metrics Flattened metrics array
     * @param bool $isPoller Whether this is being called from poller
     * @param string $resourceType Resource type (device, port, storage, etc.)
     * @param array $metricMap Optional metric mapping from endpoint config
     * @param string $endpointName Name of the endpoint being processed
     * @param array $itemContext Optional context about the specific item being processed (name, id, etc.)
     */
    public function stageMetrics(
        array $metrics, 
        bool $isPoller = false, 
        string $resourceType = 'custom', 
        array $metricMap = [], 
        string $endpointName = 'unknown',
        array $itemContext = []
    ): void
    {
        $contextInfo = !empty($itemContext) ? " (item: " . ($itemContext['name'] ?? $itemContext['id'] ?? 'unnamed') . ")" : "";
        
        Log::info("═══════════════════════════════════════════════════════════════");
        Log::info("METRICS STAGER - START");
        Log::info("═══════════════════════════════════════════════════════════════");
        Log::info("[{$endpointName}] Device: {$this->device->hostname} (ID: {$this->device->device_id})");
        Log::info("[{$endpointName}] Resource Type: {$resourceType}");
        Log::info("[{$endpointName}] Endpoint: {$endpointName}");
        Log::info("[{$endpointName}] Is Poller: " . ($isPoller ? 'YES' : 'NO'));
        Log::info("[{$endpointName}] Metrics Count: " . count($metrics));
        
        if (!empty($itemContext)) {
            Log::info("[{$endpointName}] Item Context:");
            foreach ($itemContext as $key => $value) {
                Log::info("[{$endpointName}]   {$key}: {$value}");
            }
        }
        
        if (!empty($metricMap)) {
            Log::info("[{$endpointName}] Metric Map provided with " . count($metricMap) . " mappings");
        }
        
        // Show sample of metrics
        Log::info("[{$endpointName}] Sample Metrics (first 10):");
        $count = 0;
        foreach ($metrics as $key => $value) {
            if ($count++ >= 10) break;
            $displayValue = is_array($value) ? '[ARRAY]' : (is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
            Log::info("[{$endpointName}]   {$key} = {$displayValue}");
        }
        
        if (count($metrics) > 10) {
            Log::info("[{$endpointName}]   ... and " . (count($metrics) - 10) . " more metrics");
        }
        
        Log::info("[{$endpointName}] Calling DataRouter->route()...");
        Log::info("───────────────────────────────────────────────────────────────");
        
        // Use DataRouter to intelligently route each metric
        $this->router->route($metrics, $resourceType, $metricMap, $endpointName, $itemContext);
        
        Log::info("───────────────────────────────────────────────────────────────");
        Log::info("[{$endpointName}] DataRouter->route() completed");
        Log::info("═══════════════════════════════════════════════════════════════");
        Log::info("METRICS STAGER - END");
        Log::info("═══════════════════════════════════════════════════════════════");
        Log::info("");
    }
}
