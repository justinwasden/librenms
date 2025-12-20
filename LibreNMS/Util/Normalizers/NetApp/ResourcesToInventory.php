<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - ResourcesToInventory Normalizer
 *
 * Capability: inventory
 * Vendor: unity
 */
class ResourcesToInventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'unity';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $res = $entry['content'] ?? $entry;
            $name = $res['name'] ?? ($res['id'] ?? 'resource');
            $index = $this->stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Unity Resource: $name",
                'entPhysicalClass'        => 'other',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $res['type'] ?? '',
                'entPhysicalSerialNum'    => $res['id'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'storageResource',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }
}
