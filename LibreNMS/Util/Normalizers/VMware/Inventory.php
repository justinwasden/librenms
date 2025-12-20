<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Inventory Normalizer
 *
 * Capability: inventory
 * Vendor: velocloud
 */
class Inventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $inventory = [];

        if (!is_array($data)) {
            return [];
        }

        $index = 1;
        foreach ($data as $edge) {
            $edgeId = $edge['id'] ?? $index;
            $edgeName = $edge['name'] ?? "Edge-{$edgeId}";
            $state = $edge['edgeState'] ?? 'UNKNOWN';
            $activationState = $edge['activationState'] ?? 'UNKNOWN';

            $inventory[] = [
                'entPhysicalIndex' => $index++,
                'entPhysicalDescr' => "VeloCloud Edge: {$edgeName} [{$state}]",
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $edgeName,
                'entPhysicalModelName' => $edge['modelNumber'] ?? 'VeloCloud Edge',
                'entPhysicalSerialNum' => $edge['serialNumber'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'VMware',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'velocloud-edge',
                'entPhysicalHardwareRev' => $edge['buildNumber'] ?? '',
                'entPhysicalFirmwareRev' => $edge['softwareVersion'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => $edge['description'] ?? '',
                'entPhysicalAssetID' => (string)$edgeId,
            ];
        }

        return $inventory;
    }
}
