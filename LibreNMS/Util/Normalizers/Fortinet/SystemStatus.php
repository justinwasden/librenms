<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - SystemStatus Normalizer
 *
 * Capability: device_info
 * Vendor: fortigate
 */
class SystemStatus extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $sensors = [];

        $results = $payload['results'] ?? $payload;

        // System inventory
        if (isset($results['serial'])) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => ($results['hostname'] ?? 'FortiGate') . ' Chassis',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $results['hostname'] ?? 'FortiGate',
                'entPhysicalModelName' => $results['model'] ?? '',
                'entPhysicalSerialNum' => $results['serial'],
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Fortinet',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'fortigate',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $results['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
}
