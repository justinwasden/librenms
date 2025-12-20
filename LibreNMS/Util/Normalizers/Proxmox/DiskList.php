<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - DiskList Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class DiskList extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $data = $payload['data'] ?? $payload;

        if (!is_array($data)) {
            return ['inventory' => $inventory];
        }

        foreach ($data as $disk) {
            $devpath = $disk['devpath'] ?? '';
            if (empty($devpath)) {
                continue;
            }

            $index = $this->stableIndexFromName($devpath);
            $model = $disk['model'] ?? '';
            $serial = $disk['serial'] ?? '';
            $size = $disk['size'] ?? 0;
            $wwn = $disk['wwn'] ?? '';
            $vendor = $disk['vendor'] ?? '';
            $type = $disk['type'] ?? 'disk';

            // Create inventory entry
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Disk: ' . $devpath . ($model ? " ($model)" : ''),
                'entPhysicalClass' => 'disk',
                'entPhysicalName' => $devpath,
                'entPhysicalModelName' => $model,
                'entPhysicalSerialNum' => $serial,
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => $vendor,
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => $type,
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => $wwn,
                'entPhysicalAssetID' => '',
                // Store devpath for SMART polling
                'devpath' => $devpath,
            ];
        }

        return ['inventory' => $inventory];
    }
}
