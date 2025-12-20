<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - VolumesToStorageDb Normalizer
 *
 * Capability: storage
 * Vendor: ontap
 */
class VolumesToStorageDb extends BaseNormalizer
{
    protected string $capability = 'storage';
    protected string $vendor = 'ontap';

    protected function doNormalize(Device $device, array $payload): array
    {
$rows = [];
        $list = $payload['records'] ?? $payload['items'] ?? [];

        foreach ($list as $vol) {
            $name = $vol['name'] ?? 'volume';
            $index = $this->stableIndexFromName($name);
            $size = (int) ($vol['space']['size'] ?? $vol['size'] ?? 0);
            $used = (int) ($vol['space']['used'] ?? $vol['used'] ?? 0);
            $free = $size > 0 ? max(0, $size - $used) : null;

            $rows[] = [
                'type'          => 'array-volume',
                'storage_index' => "ontap_vol_$index",
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
