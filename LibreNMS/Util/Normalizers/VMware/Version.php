<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Version Normalizer
 *
 * Capability: unknown
 * Vendor: esxi
 */
class Version extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'esxi';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];

        $value = $payload['value'] ?? $payload;

        if (isset($value['version'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'ESXi Host',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'ESXi',
                'entPhysicalModelName' => $value['product'] ?? 'ESXi',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'VMware',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'esxi',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $value['version'] ?? '',
                'entPhysicalSoftwareRev' => $value['build'] ?? '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return $inventory;
    }
}
