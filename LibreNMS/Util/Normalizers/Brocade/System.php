<?php

namespace LibreNMS\Util\Normalizers\Brocade;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Brocade - System Normalizer
 *
 * Capability: device_info
 * Vendor: brocade
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'brocade';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $chassis = $payload['Response']['chassis'] ?? [];

        if (!empty($chassis)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Brocade Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $chassis['chassis-user-friendly-name'] ?? 'Brocade',
                'entPhysicalModelName' => $chassis['product-name'] ?? '',
                'entPhysicalSerialNum' => $chassis['serial-number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Brocade',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'brocade',
                'entPhysicalHardwareRev' => '',
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
