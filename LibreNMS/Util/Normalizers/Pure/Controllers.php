<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Controllers Normalizer
 *
 * Normalizes Pure Storage controller data into inventory, sensors, and processors.
 * Controllers are the compute units of Pure Storage arrays.
 *
 * Capability: processors, inventory, sensors
 * Vendor: pure
 */
class Controllers extends BaseNormalizer
{
    protected string $capability = 'processors';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
        $inventory = [];
        $sensors = [];
        $processors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['inventory' => $inventory, 'sensors' => $sensors, 'processors' => $processors];
        }

        foreach ($payload['items'] as $idx => $ctrl) {
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

            // Processor entry for each controller
            // Pure Storage controllers are the compute units - track them as processors
            // Note: Pure doesn't expose CPU % via API, so we use status-based representation
            $processors[] = [
                'processor_index' => $idx,
                'processor_type' => 'purestorage-ctrl',
                'processor_descr' => 'Controller ' . $name . ($ctrl['model'] ? ' (' . $ctrl['model'] . ')' : ''),
                'processor_usage' => null,  // Pure API doesn't expose CPU utilization
            ];
        }

        return ['inventory' => $inventory, 'sensors' => $sensors, 'processors' => $processors];
    }
}
