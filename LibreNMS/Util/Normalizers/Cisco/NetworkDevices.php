<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - NetworkDevices Normalizer
 *
 * Capability: ports
 * Vendor: ise
 */
class NetworkDevices extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'ise';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $devices = $payload['SearchResult']['resources'] ?? [];

        foreach ($devices as $idx => $device) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1,
                'entPhysicalDescr' => "Network Device: {$device['name']}",
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $device['name'] ?? "Device $idx",
                'entPhysicalModelName' => $device['NetworkDeviceGroupList'] ?? '',
                'entPhysicalSerialNum' => $device['id'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Cisco',
                'entPhysicalParentRelPos' => $idx,
                'entPhysicalVendorType' => 'cisco-ise',
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
