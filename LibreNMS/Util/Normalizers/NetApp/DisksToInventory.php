<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - DisksToInventory Normalizer
 *
 * Capability: inventory
 * Vendor: unity
 */
class DisksToInventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'unity';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $entries = $payload['entries'] ?? [];

        foreach ($entries as $entry) {
            $d = $entry['content'] ?? $entry;
            $name = $d['name'] ?? ($d['id'] ?? 'disk');
            $index = $this->stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Unity Disk: $name",
                'entPhysicalClass'        => 'diskDrive',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $d['model'] ?? '',
                'entPhysicalSerialNum'    => $d['emcSerialNumber'] ?? ($d['serialNumber'] ?? ''),
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => (int)($d['slotNumber'] ?? -1),
                'entPhysicalVendorType'   => 'disk',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $d['firmwareRevision'] ?? '',
                'entPhysicalSoftwareRev'  => '',
                'entPhysicalIsFRU'        => 1,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }
}
