<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - Devices Normalizer
 *
 * Capability: unknown
 * Vendor: ndfc
 */
class Devices extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'ndfc';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $devices = $payload['DATA'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Switch: {$device['logicalName']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['logicalName'] ?? "Device $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-ndfc',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $device['release'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
