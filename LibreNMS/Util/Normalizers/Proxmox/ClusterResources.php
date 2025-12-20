<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - ClusterResources Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class ClusterResources extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        // This function is deprecated and no longer creates sensors to avoid duplicates
        // with normalizeProxmoxGuestDiscovery(). If you need VM/Container counts,
        // use the 'discovery' capability with normalizeProxmoxGuestDiscovery instead.

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
}
