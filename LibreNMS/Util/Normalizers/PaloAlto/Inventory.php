<?php

namespace LibreNMS\Util\Normalizers\PaloAlto;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * PaloAlto - Inventory Normalizer
 *
 * Capability: inventory
 * Vendor: pan
 */
class Inventory extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'pan';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $system = $payload['result']['system'] ?? [];

        if (!empty($system)) {
            $inventory[] = [
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Palo Alto Firewall',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $system['hostname'] ?? 'PA-Firewall',
                'entPhysicalModelName' => $system['model'] ?? '',
                'entPhysicalSerialNum' => $system['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Palo Alto Networks',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'paloalto',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $system['sw-version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];
        }

        return ['inventory' => $inventory];
    }
}
