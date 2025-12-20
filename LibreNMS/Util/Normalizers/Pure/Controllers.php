<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Controllers Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class Controllers extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$inventory = [];
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['inventory' => $inventory, 'sensors' => $sensors];
        }

        foreach ($payload['items'] as $ctrl) {
            $name = $ctrl['name'] ?? 'controller';
            $index = $this->stableIndexFromName($name);

            $inventory[] = [
                'entPhysicalIndex' => $index + 30000,
                'entPhysicalDescr' => 'Controller: ' . $name,
                'entPhysicalClass' => 'module',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $ctrl['model'] ?? '',
                'entPhysicalSerialNum' => $ctrl['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'controller',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $ctrl['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Controller status sensor
            if (isset($ctrl['status'])) {
                $statusMap = ['ok' => 2, 'critical' => 0, 'warning' => 1, 'unknown' => 3];
                $statusValue = $statusMap[strtolower($ctrl['status'])] ?? 3;

                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Status',
                    'sensor_index' => 'ctrl_status_' . $index,
                    'sensor_current' => $statusValue,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'critical'],
                        ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'warning'],
                        ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'ok'],
                        ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                    ],
                ];
            }
        }

        return ['inventory' => $inventory, 'sensors' => $sensors];
    }
}
