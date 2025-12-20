<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Hardware Normalizer
 *
 * Capability: inventory
 * Vendor: pure
 */
class Hardware extends BaseNormalizer
{
    protected string $capability = 'inventory';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
// Handle when called with wrong types (e.g., from TransformRunner fallback)
        if (!is_array($payload)) {
            return ['sensors' => [], 'inventory' => []];
        }

        $sensors = [];
        $inventory = [];
        $parentIndices = []; // Track parent device indices for hierarchy

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        // First pass: Identify all potential parent devices and build index map
        // A parent device is one that doesn't contain a dot (not a child component)
        // Examples: CT0, CT1, CH0, CH1, SH0, SH1, NODE0, etc.
        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';

            // If name doesn't contain a dot, it's a potential parent
            if (strpos($name, '.') === false) {
                $index = $this->stableIndexFromName($name);
                // Store with case-insensitive key for matching
                $parentIndices[strtoupper($name)] = $index;
            }
        }

        // Second pass: Create all inventory items with proper hierarchy
        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';
            $type = $hw['type'] ?? 'unknown';
            $status = $hw['status'] ?? 'unknown';

            // Skip NVRAM (NVB) and drive bay (BAY) devices
            // These can come from /hardware endpoint (type: drive_bay, nvram_bay)
            // or /drives endpoint (type: SSD, NVRAM)
            $typeLower = strtolower($type);
            if ($typeLower === 'nvram_bay' || $typeLower === 'drive_bay' || $typeLower === 'ssd' || $typeLower === 'nvram') {
                continue;
            }

            // Also skip empty drive bays (name contains .BAY and status is unknown)
            if (stripos($name, '.BAY') !== false && strtolower($status) === 'unknown') {
                continue;
            }

            $index = $this->stableIndexFromName($name);

            // Determine parent device
            $parentIndex = 0;
            // Check if name contains a dot (e.g., "CT0.FC0", "CH1.PSU0", "SH0.DISK1")
            if (strpos($name, '.') !== false) {
                // Extract parent name (everything before the first dot)
                $parts = explode('.', $name, 2);
                $parentName = strtoupper($parts[0]);

                // Look up parent index
                if (isset($parentIndices[$parentName])) {
                    $parentIndex = $parentIndices[$parentName];
                }
            }

            // Inventory entry
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => $name,
                'entPhysicalClass' => $this->mapPureHardwareType($type),
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $hw['model'] ?? '',
                'entPhysicalSerialNum' => $hw['serial'] ?? '',
                'entPhysicalContainedIn' => $parentIndex,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => $hw['slot'] ?? -1,
                'entPhysicalVendorType' => $type,
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $hw['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // State sensor for component health
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'purestorage',
                'sensor_descr' => $name . ' Status',
                'sensor_index' => 'hw_' . $index,
                'sensor_current' => $this->pureStatusToNumeric($status),
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'critical'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'healthy'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];

            // Temperature sensors
            if (isset($hw['temperature']) && is_numeric($hw['temperature'])) {
                $sensors[] = [
                    'sensor_class' => 'temperature',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Temperature',
                    'sensor_index' => 'hw_temp_' . $index,
                    'sensor_current' => round($hw['temperature']),
                    'sensor_limit' => 85,
                    'sensor_limit_low' => 0,
                ];
            }

            // Voltage sensors (for PSUs)
            if ($type === 'psu' && isset($hw['voltage']) && is_numeric($hw['voltage'])) {
                $sensors[] = [
                    'sensor_class' => 'voltage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Voltage',
                    'sensor_index' => 'hw_volt_' . $index,
                    'sensor_current' => $hw['voltage'],
                    'sensor_limit' => 13,
                    'sensor_limit_low' => 11,
                ];
            }

            // Fan speed (RPM)
            if ($type === 'fan' && isset($hw['speed']) && is_numeric($hw['speed'])) {
                $sensors[] = [
                    'sensor_class' => 'fanspeed',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Speed',
                    'sensor_index' => 'hw_fan_' . $index,
                    'sensor_current' => $hw['speed'],
                    'sensor_limit' => 20000,
                    'sensor_limit_low' => 1000,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }
}
