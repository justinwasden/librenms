<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - DiskUsage Normalizer
 *
 * Capability: unknown
 * Vendor: ftd
 */
class DiskUsage extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'ftd';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];

        // FTD returns disk usage data
        if (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $disk) {
                $diskName = $disk['diskName'] ?? $disk['mountPoint'] ?? 'disk';
                $diskName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $diskName);

                // Disk usage percentage
                if (isset($disk['usedPercent'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'ftd-disk',
                        'sensor_descr' => "Disk {$diskName} Usage",
                        'sensor_index' => "ftd_disk_{$diskName}_usage",
                        'sensor_current' => $disk['usedPercent'],
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Disk capacity in GB
                if (isset($disk['capacity'])) {
                    $capacityGB = round($disk['capacity'] / (1024 * 1024 * 1024), 2);
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'ftd-disk',
                        'sensor_descr' => "Disk {$diskName} Capacity (GB)",
                        'sensor_index' => "ftd_disk_{$diskName}_capacity",
                        'sensor_current' => $capacityGB,
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        } elseif (isset($payload['usedPercent'])) {
            // Single disk usage response
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'ftd-disk',
                'sensor_descr' => 'Disk Usage',
                'sensor_index' => 'ftd_disk_usage',
                'sensor_current' => $payload['usedPercent'],
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        return $sensors;
    }
}
