<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - ArrayConnections Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class ArrayConnections extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $inventory;
        }

        foreach ($payload['items'] as $idx => $conn) {
            $name = $conn['array_name'] ?? 'connection_' . $idx;
            $index = $this->stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 10000,
                'entPhysicalDescr' => 'Array Connection: ' . $name,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $conn['type'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'array-connection',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $conn['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return $inventory;
    }
}
