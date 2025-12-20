<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - Inventory Normalizer
 *
 * Capability: inventory
 * Vendor: cucm
 */
class Inventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'cucm';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $devices = $payload['return']['phone'] ?? [];

        if (isset($devices['name'])) {
            $devices = [$devices];
        }

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Phone: {$device['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Phone $idx",
                'entPhysicalModelName' => $device['model'] ?? '',
                'entPhysicalSerialNum' => $device['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-cucm',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => $device['loadInformation'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
