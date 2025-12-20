<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - System Normalizer
 *
 * Capability: device_info
 * Vendor: hpe
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'hpe';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $system = $payload['System'] ?? $payload;

        $inventory[] = [
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => $system['Model'] ?? 'HPE System',
            'entPhysicalClass' => 'chassis',
            'entPhysicalName' => 'Chassis',
            'entPhysicalModelName' => $system['Model'] ?? '',
            'entPhysicalSerialNum' => $system['SerialNumber'] ?? '',
            'entPhysicalContainedIn' => 0,
            'entPhysicalMfgName' => 'HPE',
            'entPhysicalParentRelPos' => -1,
            'entPhysicalVendorType' => 'hpe',
            'entPhysicalHardwareRev' => '',
            'entPhysicalFirmwareRev' => $system['BiosVersion'] ?? '',
            'entPhysicalSoftwareRev' => $system['Firmware'] ?? '',
            'entPhysicalIsFRU' => 0,
            'entPhysicalAlias' => '',
            'entPhysicalAssetID' => '',
        ];

        return ['inventory' => $inventory];
    }
}
