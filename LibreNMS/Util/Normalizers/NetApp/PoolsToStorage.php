<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - PoolsToStorage Normalizer
 *
 * Capability: storage
 * Vendor: isilon
 */
class PoolsToStorage extends BaseNormalizer
{
    protected string $capability = 'storage';
    protected string $vendor = 'isilon';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $list = $payload['pools'] ?? $payload['items'] ?? [];

        foreach ($list as $pool) {
            $name = $pool['name'] ?? 'pool';
            $index = $this->stableIndexFromName($name);
            $size = (int)($pool['size'] ?? 0);
            $used = (int)($pool['used'] ?? 0);

            if ($size > 0) {
                $pct = ($used / $size) * 100;
                $sensors[] = [
                    'sensor_class'   => 'percent',
                    'sensor_type'    => 'isilon',
                    'sensor_descr'   => "Pool $name Used",
                    'sensor_index'   => "isilon_pool_used_$index",
                    'sensor_current' => round($pct, 2),
                    'sensor_limit'   => 90,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return $sensors;
    }
}
