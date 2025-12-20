<?php

namespace LibreNMS\Util\Normalizers\Arista;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Arista - System Normalizer
 *
 * Capability: device_info
 * Vendor: arista
 */
class System extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'arista';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];

        if (isset($payload['modelName'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Arista Switch',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $payload['hostname'] ?? 'Arista',
                'entPhysicalModelName' => $payload['modelName'] ?? '',
                'entPhysicalSerialNum' => $payload['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Arista',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'arista',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $payload['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
