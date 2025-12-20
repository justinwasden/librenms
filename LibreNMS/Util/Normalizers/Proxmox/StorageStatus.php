<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - StorageStatus Normalizer
 *
 * Capability: sensors
 * Vendor: proxmox
 */
class StorageStatus extends BaseNormalizer
{
    protected string $capability = 'sensors';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$storage = [];
        $data = $payload['data'] ?? $payload;

        if (empty($data) || !is_array($data)) {
            return ['storage' => $storage];
        }

        // Extract storage ID from parent item if available (from for_each loop)
        $storageId = $data['_parent_item']['storage'] ?? $data['storage'] ?? 'unknown';
        $storageType = $data['type'] ?? 'unknown';

        $total = $data['total'] ?? 0;
        $used = $data['used'] ?? 0;
        $avail = $data['avail'] ?? 0;

        // If avail is not provided, calculate it
        if ($avail === 0 && $total > 0 && $used > 0) {
            $avail = $total - $used;
        }

        $storage[] = [
            'storage_index' => 'proxmox_' . $this->stableIndexFromName($storageId),
            'storage_descr' => $storageId,
            'storage_type' => $storageType,
            'storage_size' => $total,
            'storage_used' => $used,
            'storage_free' => $avail,
            'storage_units' => 1,
            'storage_perc' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
        ];

        return ['storage' => $storage];
    }
}
