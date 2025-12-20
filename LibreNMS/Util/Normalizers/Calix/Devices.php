<?php

namespace LibreNMS\Util\Normalizers\Calix;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Calix - Devices Normalizer
 *
 * Capability: unknown
 * Vendor: calix
 */
class Devices extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'calix';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $devices = $payload['devices'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => $device['type'] ?? "Device $idx",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Device $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Calix',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'calix',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $device['softwareVersion'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
