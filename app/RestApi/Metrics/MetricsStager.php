<?php

namespace App\RestApi\Metrics;

use App\Models\Device;
use App\RestApi\Data\DataRouter;
use Log;

/**
 * Simple Metrics Stager - Delegates directly to DataRouter using template mappings
 */
class MetricsStager
{
    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Stage metrics using template mappings
     *
     * @param array $rawResponse Raw API response
     * @param array $mappings Template mappings
     * @param string $endpointName Endpoint name for logging
     */
    public function stageMetrics(
        array $rawResponse,
        array $mappings = [],
        string $endpointName = 'unknown'
    ): void
    {
        Log::info("[{$endpointName}] MetricsStager: Processing response");

        if (empty($mappings)) {
            Log::warning("[{$endpointName}] No mappings provided");
            return;
        }

        $router = new DataRouter($this->device, $mappings);
        $router->routeByTemplate($rawResponse, $mappings, $endpointName);

        Log::info("[{$endpointName}] MetricsStager: Complete");
    }
}
