<?php

namespace LibreNMS\Util\Normalizers\Nutanix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Nutanix - Clusters Normalizer
 *
 * Capability: unknown
 * Vendor: nutanix
 */
class Clusters extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'nutanix';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $clusters = $payload['entities'] ?? [];

        foreach ($clusters as $idx => $cluster) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Nutanix Cluster: {$cluster['name']}",
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $cluster['name'] ?? "Cluster $idx",
                'entPhysicalModelName' => 'Nutanix Cluster',
                'entPhysicalSerialNum' => $cluster['cluster_uuid'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Nutanix',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'nutanix',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $cluster['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
