<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - VolumesToStorage Normalizer
 *
 * Capability: storage
 * Vendor: ontap
 */
class VolumesToStorage extends BaseNormalizer
{
    protected string $capability = 'storage';
    protected string $vendor = 'ontap';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'unknown';
            $index = $this->stableIndexFromName($name);
            $size = (int)($vol['space']['size'] ?? $vol['size'] ?? 0);
            $used = (int)($vol['space']['used'] ?? $vol['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Volume $name Used",
                    'sensor_index'   => "ontap_vol_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
                $sensors[] = [
                    'sensor_class'   => 'count',
                    'sensor_type'    => 'netapp',
                    'sensor_descr'   => "Volume $name Size",
                    'sensor_index'   => "ontap_vol_size_$index",
                    'sensor_current' => $size,
                    'sensor_limit'   => null,
                    'sensor_limit_low' => 0,
                    'user_func'      => 'format_bytes',
                ];
            }
        }

        return $sensors;
    }
}
