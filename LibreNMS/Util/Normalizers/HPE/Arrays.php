<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - Arrays Normalizer
 *
 * Capability: unknown
 * Vendor: nimble
 */
class Arrays extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'nimble';

    protected function doNormalize(Device $device, array $payload): array
    {
$storage = [];
        $arrays = $payload['data'] ?? $payload['arrays'] ?? [];

        foreach ($arrays as $idx => $array) {
            $name = $array['name'] ?? "array-$idx";
            $usageBytes = $array['usage_bytes'] ?? 0;
            $capacityBytes = $array['capacity_bytes'] ?? 0;

            $storage[] = [
                'storage_index' => "nimble-array-$idx",
                'storage_descr' => $name,
                'storage_type' => 'nimble-array',
                'storage_size' => $capacityBytes,
                'storage_used' => $usageBytes,
                'storage_free' => $capacityBytes - $usageBytes,
                'storage_units' => 1,
                'storage_perc' => $capacityBytes > 0 ? round(($usageBytes / $capacityBytes) * 100, 2) : 0,
            ];
        }

        return ['storage' => $storage];
    }
}
