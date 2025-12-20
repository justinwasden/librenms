<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - VolumesToStorage Normalizer
 *
 * Capability: storage
 * Vendor: pure
 */
class VolumesToStorage extends BaseNormalizer
{
    protected string $capability = 'storage';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$rows = [];
        $list = $payload['items'] ?? $payload['records'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'volume';
            $index = $this->stableIndexFromName($name);
            $size = (int) ($vol['size'] ?? $vol['provisioned'] ?? 0);
            $used = (int) ($vol['space']['total_physical'] ?? $vol['space']['used'] ?? 0);
            $free = $size > 0 ? max(0, $size - $used) : null;

            $rows[] = [
                'type'          => 'array-volume',
                'storage_index' => "pure_vol_$index",
                'storage_descr' => $name,
                'storage_size'  => $size,
                'storage_used'  => $used,
                'storage_free'  => $free,
                'storage_units' => 1, // bytes
                'storage_perc'  => $size > 0 ? round(($used / $size) * 100, 2) : null,
                'storage_perc_warn' => \LibrenmsConfig::get('storage_perc_warn', 80),
            ];
        }

        return $rows;
    }
}
