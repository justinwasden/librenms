<?php

namespace LibreNMS\Util\Normalizers\Juniper;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Juniper - Inventory Normalizer
 *
 * Capability: inventory
 * Vendor: junos
 */
class Inventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'junos';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $chassis = $payload['chassis-inventory']['chassis'] ?? $payload['chassis'] ?? [];

        if (!empty($chassis)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => $chassis['description'] ?? 'Juniper Chassis',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'Chassis',
                'entPhysicalModelName' => $chassis['model'] ?? '',
                'entPhysicalSerialNum' => $chassis['serial-number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Juniper',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'junos',
                'entPhysicalHardwareRev' => $chassis['hardware-version'] ?? '',
                'entPhysicalFirmwareRev' => $chassis['firmware-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
