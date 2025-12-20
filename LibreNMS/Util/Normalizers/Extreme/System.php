<?php

namespace LibreNMS\Util\Normalizers\Extreme;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Extreme - System Normalizer
 *
 * Capability: device_info
 * Vendor: extreme
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'extreme';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $system = $payload['openconfig-system:system']['state'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Extreme Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'Extreme',
                'entPhysicalModelName' => '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Extreme Networks',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'extreme',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
