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
        Log::debug("[{$endpointName}] Staging " . count($metrics) . " metrics for {$this->device->hostname} (resource: {$resourceType}){$contextInfo}");
        
        // Use DataRouter to intelligently route each metric
        $this->router->route($metrics, $resourceType, $metricMap, $endpointName, $itemContext);
    }
}
