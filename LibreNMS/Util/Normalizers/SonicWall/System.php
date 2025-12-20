<?php

namespace LibreNMS\Util\Normalizers\SonicWall;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * SonicWall - System Normalizer
 *
 * Capability: device_info
 * Vendor: sonic
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'sonic';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $system = $payload['status'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'SonicWall Firewall',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'SonicWall',
                'entPhysicalModelName' => $system['model'] ?? '',
                'entPhysicalSerialNum' => $system['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'SonicWall',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'sonicwall',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['firmware-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
