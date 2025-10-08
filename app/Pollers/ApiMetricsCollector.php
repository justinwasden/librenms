<?php
namespace App\Pollers;

use Illuminate\Support\Facades\DB;
use Log;

class ApiMetricsCollector
{
    use \App\RestApi\Traits\NormalizeResourceTrait;

    protected $device;

    public function __construct($device)
    {
        $this->device = $device;
    }

    /**
     * Insert a metric into the LibreNMS DB + RRD
     */
    public function storeMetric(string $resourceType, string $metricName, $value, array $labels = [])
    {
        $normalizedMetric = $this->normalizeByResourceType($resourceType, $metricName, $value, $labels);

        // Insert into LibreNMS table based on type
        switch ($normalizedMetric['type']) {
            case 'mempool':
            case 'processor':
            case 'storage':
            case 'port':
            case 'sensor':
                $this->insertOrUpdateMetric($normalizedMetric);
                break;
            default:
                // fallback to device_api_metrics
                DB::table('device_api_metrics')->updateOrInsert(
                    [
                        'device_id' => $this->device->id,
                        'resource_type' => $resourceType,
                        'metric_name' => $metricName
                    ],
                    ['value' => $value, 'labels' => json_encode($labels)]
                );
        }
    }

    protected function insertOrUpdateMetric(array $metric)
    {
        $table = $this->getTableForType($metric['type']);

        DB::table($table)->updateOrInsert(
            [
                'device_id' => $this->device->id,
                'metric_descr' => $metric['descr'] ?? $metric['metric_name'],
            ],
            [
                'metric_value' => $metric['value'],
                'metric_time' => now()
            ]
        );

        // Update RRD
        $this->updateRrd($metric);
    }

    protected function getTableForType(string $type)
    {
        return match($type) {
            'processor' => 'processors',
            'mempool'   => 'mempools',
            'port'      => 'ports',
            'storage'   => 'storage',
            'sensor'    => 'sensors',
            default     => 'device_api_metrics'
        };
    }

    protected function updateRrd(array $metric)
    {
        $rrdPath = "/opt/librenms/rrd/{$this->device->hostname}/{$metric['metric_name']}.rrd";
        if (!file_exists($rrdPath)) {
            // create RRD
            rrd_create($rrdPath, [
                "DS:value:GAUGE:600:U:U",
                "RRA:AVERAGE:0.5:1:288",
                "RRA:AVERAGE:0.5:6:336",
                "RRA:AVERAGE:0.5:24:365"
            ]);
        }

        rrd_update($rrdPath, "N:{$metric['value']}");
    }
}
