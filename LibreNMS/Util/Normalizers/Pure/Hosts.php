<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Hosts Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class Hosts extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
if (!is_array($payload)) {
            return ['sensors' => [], 'inventory' => []];
        }

        $sensors = [];
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['items'] as $host) {
            $name = $host['name'] ?? 'unknown';
            $index = $this->stableIndexFromName($name);

            // Inventory for connected hosts
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Host: ' . $name,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $host['personality'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => '',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'host',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // NOTE: Host connection state and count sensors have been removed.
            // This data is now stored in the storage_hosts table with columns:
            // - port_connectivity_status (e.g., 'healthy', 'degraded', 'unhealthy')
            // - port_connectivity_details (e.g., connection count and port details)
            // See storage_hosts table and FlashArrayClient::fetchHosts() for current implementation.
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
}
