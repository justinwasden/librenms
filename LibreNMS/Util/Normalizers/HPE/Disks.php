<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - Disks Normalizer
 *
 * Capability: unknown
 * Vendor: nimble
 */
class Disks extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'nimble';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $disks = $payload['data'] ?? $payload['disks'] ?? [];

        foreach ($disks as $idx => $disk) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 10,
                'entPhysicalDescr' => "Disk: {$disk['serial']}",
                'entPhysicalClass' => 'disk',
                'entPhysicalName' => $disk['model'] ?? "Disk $idx",
                'entPhysicalModelName' => $disk['model'] ?? '',
                'entPhysicalSerialNum' => $disk['serial'] ?? '',
                'entPhysicalContainedIn' => 1,
                'entPhysicalMfgName' => 'HPE Nimble',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'nimble',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $disk['firmware_version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
