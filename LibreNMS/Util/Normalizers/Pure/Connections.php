<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Connections Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class Connections extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
if (!is_array($payload)) {
            return [];
        }

        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $inventory;
        }

        foreach ($payload['items'] as $idx => $conn) {
            $host = $conn['host']['name'] ?? 'host_' . $idx;
            $volume = $conn['volume']['name'] ?? 'volume_' . $idx;
            $name = $host . '_' . $volume;
            $index = $this->stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 20000,
                'entPhysicalDescr' => 'Connection: ' . $host . ' -> ' . $volume,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $conn['protocol'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'host-volume-connection',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => $conn['lun'] ?? '',
            ];
        }

        return $inventory;
    }
}
