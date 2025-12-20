<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - NodesToInventory Normalizer
 *
 * Capability: inventory
 * Vendor: isilon
 */
class NodesToInventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'isilon';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $list = $payload['nodes'] ?? $payload['items'] ?? [];

        foreach ($list as $node) {
            $name = $node['name'] ?? 'node';
            $index = $this->stableIndexFromName($name);
            $inventory[] = [
                'entPhysicalIndex'        => $index,
                'entPhysicalDescr'        => "Isilon Node: $name",
                'entPhysicalClass'        => 'chassis',
                'entPhysicalName'         => $name,
                'entPhysicalModelName'    => $node['model'] ?? '',
                'entPhysicalSerialNum'    => $node['serial_number'] ?? '',
                'entPhysicalContainedIn'  => 0,
                'entPhysicalMfgName'      => 'Dell EMC',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType'   => 'node',
                'entPhysicalHardwareRev'  => '',
                'entPhysicalFirmwareRev'  => $node['firmware'] ?? '',
                'entPhysicalSoftwareRev'  => $node['onefs_version'] ?? '',
                'entPhysicalIsFRU'        => 0,
                'entPhysicalAlias'        => '',
                'entPhysicalAssetID'      => '',
            ];
        }

        return $inventory;
    }
}
