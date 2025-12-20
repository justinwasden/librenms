<?php

namespace LibreNMS\Util\Normalizers\Nutanix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Nutanix - Storage Normalizer
 *
 * Capability: storage
 * Vendor: nutanix
 */
class Storage extends BaseNormalizer
{
    protected string $capability = 'storage';
    protected string $vendor = 'nutanix';

    protected function doNormalize(Device $device, array $payload): array
    {
$storage = [];
        $containers = $payload['entities'] ?? [];

        foreach ($containers as $idx => $container) {
            $usageBytes = $container['usage_stats']['storage.usage_bytes'] ?? 0;
            $capacityBytes = $container['max_capacity'] ?? 0;

            $storage[] = [
                'storage_index' => "nutanix-$idx",
                'storage_descr' => $container['name'] ?? "Storage $idx",
                'storage_type' => 'nutanix-container',
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
