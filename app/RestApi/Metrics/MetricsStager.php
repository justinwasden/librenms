<?php
namespace App\RestApi\Metrics;

use App\Models\Device;
use App\RestApi\Traits\NormalizeResourceTrait;
use Illuminate\Support\Facades\DB;
use Log;

class MetricsStager
{
    use NormalizeResourceTrait;

    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Stage metrics for either discovery or polling.
     * @param array $metrics Flattened metrics with resource type and resource name
     * @param bool $isPoller True if this is a poller run (RRD updates)
     */
    public function stageMetrics(array $metrics, bool $isPoller = false)
    {
        foreach ($metrics as $metric) {
            $resourceType = $metric['resource_type'] ?? 'unknown';
            $resourceName = $metric['resource_name'] ?? null;

            $normalized = $this->normalizeByResourceType($resourceType, $metric);

            if (!$normalized) {
                Log::warning("Unknown resource type {$resourceType}, storing metric in device_api_metrics");
                $this->storeFallbackMetric($metric);
                continue;
            }

            switch ($resourceType) {
                case 'port':
                    $this->storePortMetric($normalized, $isPoller);
                    break;
                case 'sensor':
                    $this->storeSensorMetric($normalized, $isPoller);
                    break;
                case 'storage':
                    $this->storeStorageMetric($normalized, $isPoller);
                    break;
                case 'device':
                case 'controller':
                    $this->storeDeviceMetric($normalized, $isPoller);
                    break;
                default:
                    // Store anything else in device_api_metrics
                    $this->storeFallbackMetric($metric);
                    break;
            }
        }
    }

    protected function storePortMetric(array $metric, bool $isPoller)
    {
        // Match existing port by ifIndex/description or create new
        $port = $this->device->ports()->firstOrCreate([
            'ifIndex' => $metric['ifIndex'] ?? null,
            'port_descr' => $metric['descr'] ?? null,
        ]);

        $this->storeMetricToTable($port, $metric, $isPoller);
    }

    protected function storeSensorMetric(array $metric, bool $isPoller)
    {
        $sensor = $this->device->sensors()->firstOrCreate([
            'sensor_class' => $metric['sensor_class'] ?? 'generic',
            'sensor_descr' => $metric['descr'] ?? 'unknown',
        ]);

        $this->storeMetricToTable($sensor, $metric, $isPoller);
    }

    protected function storeStorageMetric(array $metric, bool $isPoller)
    {
        $storage = $this->device->storage()->firstOrCreate([
            'storage_descr' => $metric['descr'] ?? 'unknown',
        ]);

        $this->storeMetricToTable($storage, $metric, $isPoller);
    }

    protected function storeDeviceMetric(array $metric, bool $isPoller)
    {
        // Device-level metrics stored in device table
        foreach ($metric as $key => $value) {
            if ($key === 'resource_type' || $key === 'resource_name') continue;
            $this->device->setAttribute($key, $value);
        }
        $this->device->save();
    }

    protected function storeFallbackMetric(array $metric)
    {
        DB::table('device_api_metrics')->updateOrInsert([
            'device_id' => $this->device->id,
            'resource_name' => $metric['resource_name'] ?? null,
            'metric_name' => $metric['metric_name'] ?? 'unknown',
        ], [
            'metric_value' => $metric['value'] ?? null,
            'collected_at' => now(),
        ]);
    }

    protected function storeMetricToTable($model, array $metric, bool $isPoller)
		{
		    if ($isPoller) {
		        // Use LibreNMS helper to update RRD
		        try {
		            \LibreNMS\RRD::update($model, $metric['metric_name'], $metric['value']);
		        } catch (\Exception $e) {
		            Log::error("RRD update failed for {$metric['metric_name']}: {$e->getMessage()}");
		        }
		    } else {
		        $model->setAttribute($metric['metric_name'], $metric['value']);
		        $model->save();
		    }
		}
}
