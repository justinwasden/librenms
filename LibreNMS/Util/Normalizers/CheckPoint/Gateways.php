<?php

namespace LibreNMS\Util\Normalizers\CheckPoint;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * CheckPoint - Gateways Normalizer
 *
 * Capability: unknown
 * Vendor: checkpoint
 */
class Gateways extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'checkpoint';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $gateways = $payload['objects'] ?? [];

        foreach ($gateways as $idx => $gateway) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Gateway: {$gateway['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $gateway['name'] ?? "Gateway $idx",
                'entPhysicalModelName' => $gateway['hardware'] ?? '',
                'entPhysicalSerialNum' => $gateway['uid'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Check Point',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'checkpoint',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $gateway['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
