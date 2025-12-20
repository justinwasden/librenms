<?php

namespace LibreNMS\Util\Normalizers\Dell;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Dell - System Normalizer
 *
 * Capability: device_info
 * Vendor: dell
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'dell';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $system = $payload['SystemInformation'] ?? $payload['system'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => $system['Model'] ?? 'Dell System',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'Chassis',
                'entPhysicalModelName' => $system['Model'] ?? '',
                'entPhysicalSerialNum' => $system['ServiceTag'] ?? $system['SerialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Dell',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'dell',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['BIOSVersion'] ?? '',
                'entPhysicalSoftwareRev' => $system['FirmwareVersion'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => $system['AssetTag'] ?? '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
