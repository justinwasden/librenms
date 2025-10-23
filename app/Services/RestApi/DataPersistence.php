<?php

namespace App\Services\RestApi;

use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared data persistence logic for REST API processors
 *
 * This class contains the common database operations used by both
 * GenericDataProcessor and vendor-specific processors to store
 * API data into LibreNMS database tables.
 */
class DataPersistence
{
    /**
     * Apply a complete entity (row) with all its fields
     * Uses intelligent matching based on entity identifiers
     *
     * @param int $deviceId Device ID
     * @param string $table Target table name
     * @param array $entityData Complete entity data (all fields)
     * @param RestApiEndpoint $endpoint The endpoint this data came from
     */
    public static function applyEntity(int $deviceId, string $table, array $entityData, RestApiEndpoint $endpoint): void
    {
        try {
            switch ($table) {
                case 'devices':
                    self::applyDeviceEntity($deviceId, $entityData, $endpoint);
                    break;

                case 'storage':
                    self::applyStorageEntity($deviceId, $entityData, $endpoint);
                    break;

                case 'ports':
                    self::applyPortEntity($deviceId, $entityData, $endpoint);
                    break;

                case 'entPhysical':
                case 'hardware':
                    self::applyEntPhysicalEntity($deviceId, $entityData, $endpoint);
                    break;

                case 'sensors':
                    self::applySensorEntity($deviceId, $entityData, $endpoint);
                    break;

                case 'metrics':
                case 'rest_api_metrics':
                default:
                    self::applyMetricsEntity($deviceId, $entityData, $endpoint);
                    break;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Log database errors but don't fail the entire poll
            if (str_contains($e->getMessage(), 'Column not found') || str_contains($e->getMessage(), 'Unknown column')) {
                Log::warning("Database schema mismatch for table '{$table}', storing as metrics instead", [
                    'device_id' => $deviceId,
                    'table' => $table,
                    'entity_data' => $entityData,
                    'error' => $e->getMessage(),
                ]);

                // Fallback to metrics
                self::applyMetricsEntity($deviceId, $entityData, $endpoint);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Apply a single value to a table field
     */
    public static function applyValue(int $deviceId, string $table, string $column, mixed $value, RestApiEndpoint $endpoint): void
    {
        // Type conversions
        if (is_numeric($value) && !is_string($value)) {
            $value = (int) $value;
        } elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        } else {
            $value = (string) $value;
        }

        try {
            switch ($table) {
                case 'devices':
                    DB::table('devices')->where('device_id', $deviceId)->update([$column => $value]);
                    break;

                case 'storage':
                    DB::table('storage')->updateOrInsert(
                        ['device_id' => $deviceId, 'storage_descr' => 'REST Import'],
                        [$column => $value]
                    );
                    break;

                case 'ports':
                    DB::table('ports')->updateOrInsert(
                        ['device_id' => $deviceId, 'ifDescr' => 'REST Interface'],
                        [$column => $value]
                    );
                    break;

                case 'entPhysical':
                    DB::table('entPhysical')->updateOrInsert(
                        ['device_id' => $deviceId, 'entPhysicalName' => 'REST Component'],
                        [$column => $value]
                    );
                    break;

                case 'sensors':
                    DB::table('sensors')->updateOrInsert(
                        ['device_id' => $deviceId, 'sensor_descr' => 'REST Sensor'],
                        [$column => $value]
                    );
                    break;

                case 'metrics':
                case 'rest_api_metrics':
                default:
                    // Default to rest_api_metrics table for custom metrics
                    RestApiMetric::updateOrCreate(
                        [
                            'device_id' => $deviceId,
                            'metric_key' => $column,
                            'endpoint_name' => $endpoint->path,
                        ],
                        [
                            'metric_value' => (string) $value,
                            'last_updated' => now(),
                        ]
                    );
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Log database errors (e.g., column not found) but don't fail the entire poll
            if (str_contains($e->getMessage(), 'Column not found') || str_contains($e->getMessage(), 'Unknown column')) {
                Log::warning("Column '{$column}' does not exist in table '{$table}', storing as metric instead", [
                    'device_id' => $deviceId,
                    'table' => $table,
                    'column' => $column,
                    'value' => $value,
                ]);

                // Fallback to metrics table
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $column,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            } else {
                throw $e;
            }
        }
    }

    /**
     * Apply device entity data
     */
    protected static function applyDeviceEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        $validColumns = [
            'hostname', 'sysName', 'ip', 'community', 'authlevel', 'authname', 'authpass',
            'authalgo', 'cryptopass', 'cryptoalgo', 'snmpver', 'port', 'transport', 'timeout',
            'retries', 'snmp_disable', 'bgpLocalAs', 'sysObjectID', 'sysDescr', 'sysContact',
            'version', 'hardware', 'features', 'location_id', 'os', 'status', 'status_reason',
            'ignore', 'disabled', 'uptime', 'agent_uptime', 'last_polled', 'last_poll_attempted',
            'last_polled_timetaken', 'last_discovered_timetaken', 'last_discovered', 'last_ping',
            'last_ping_timetaken', 'purpose', 'type', 'serial', 'icon', 'poller_group',
            'override_sysLocation', 'notes', 'port_association_mode', 'max_depth'
        ];

        $filteredData = array_intersect_key($entityData, array_flip($validColumns));

        // Store non-standard fields as metrics
        $extraFields = array_diff_key($entityData, array_flip($validColumns));
        if (!empty($extraFields)) {
            foreach ($extraFields as $key => $value) {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => 'device.' . $key,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            }
        }

        // Update the device record
        if (!empty($filteredData)) {
            DB::table('devices')->where('device_id', $deviceId)->update($filteredData);
        }
    }

    /**
     * Apply storage entity data
     */
    protected static function applyStorageEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        $identifier = $entityData['storage_descr'] ?? $entityData['name'] ?? null;
        if (!$identifier) {
            Log::warning("No identifier found for storage entity", ['entity_data' => $entityData]);
            return;
        }

        // SMART ROUTING: Physical drives should go to entPhysical, not storage
        // Check for common drive bay naming patterns
        // TEMPORARILY DISABLED: Causing memory exhaustion on inventory page due to recursion issues
        // TODO: Fix entPhysical hierarchy and re-enable
        if (false && (preg_match('/\.(BAY|NVB|SSD|HDD|NVME)\d+$/i', $identifier) ||
            preg_match('/^(CH\d+|SH\d+)\.(BAY|NVB)/i', $identifier))) {
            Log::debug("Routing physical drive to entPhysical instead of storage: {$identifier}");

            // Convert to entPhysical format
            $hardwareData = [
                'entPhysicalName' => $identifier,
                'entPhysicalClass' => 'drive',
                'entPhysicalDescr' => $entityData['storage_type'] ?? 'Physical Drive',
            ];

            // Add capacity info if available
            if (isset($entityData['storage_size'])) {
                $hardwareData['entPhysicalModelName'] = $entityData['storage_type'] ?? 'Unknown';
            }

            // Merge other fields
            foreach ($entityData as $key => $value) {
                if (!isset($hardwareData[$key]) && $key !== 'name') {
                    $hardwareData[$key] = $value;
                }
            }

            self::applyEntPhysicalEntity($deviceId, $hardwareData, $endpoint);
            return;
        }

        // Skip physical drives - don't store them at all for now
        if (preg_match('/\.(BAY|NVB|SSD|HDD|NVME)\d+$/i', $identifier) ||
            preg_match('/^(CH\d+|SH\d+)\.(BAY|NVB)/i', $identifier)) {
            Log::debug("Skipping physical drive (entPhysical disabled): {$identifier}");
            return;
        }

        // Skip storage entities with zero or null size
        $storageSize = $entityData['storage_size'] ?? $entityData['size'] ?? $entityData['total'] ?? 0;
        $storageSizeNumeric = is_numeric($storageSize) ? (float)$storageSize : 0;
        if ($storageSizeNumeric <= 0) {
            Log::debug("Skipping storage entity with zero size: {$identifier}");
            return;
        }

        $validColumns = [
            'storage_mib', 'storage_index', 'storage_type', 'storage_descr', 'storage_size',
            'storage_units', 'storage_used', 'storage_free', 'storage_perc', 'storage_perc_warn',
            'storage_deleted'
        ];

        $filteredData = array_intersect_key($entityData, array_flip($validColumns));
        $filteredData['storage_descr'] = $identifier;

        // Store extra fields as metrics
        $extraFields = array_diff_key($entityData, array_flip($validColumns));
        if (!empty($extraFields)) {
            foreach ($extraFields as $key => $value) {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $identifier . '.' . $key,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            }
        }

        DB::table('storage')->updateOrInsert(
            [
                'device_id' => $deviceId,
                'storage_descr' => $identifier,
            ],
            $filteredData
        );
    }

    /**
     * Apply port entity data
     */
    protected static function applyPortEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        $identifier = $entityData['ifName'] ?? $entityData['name'] ?? $entityData['ifDescr'] ?? null;
        if (!$identifier) {
            Log::warning("No identifier found for port entity", ['entity_data' => $entityData]);
            return;
        }

        // Set required LibreNMS port fields with sensible defaults
        if (!isset($entityData['ifDescr'])) {
            $entityData['ifDescr'] = $identifier;
        }
        if (!isset($entityData['ifName'])) {
            $entityData['ifName'] = $identifier;
        }
        if (!isset($entityData['ifType'])) {
            $entityData['ifType'] = 'ethernetCsmacd';
        }
        if (!isset($entityData['ifOperStatus'])) {
            $entityData['ifOperStatus'] = 'up';
        }
        if (!isset($entityData['ifAdminStatus'])) {
            $entityData['ifAdminStatus'] = 'up';
        }
        // Set disabled flag based on operational status
        if (!isset($entityData['disabled'])) {
            $operStatus = $entityData['ifOperStatus'] ?? 'up';
            $entityData['disabled'] = in_array($operStatus, ['down', 'lowerLayerDown', 'notPresent']) ? 1 : 0;
        }
        // CRITICAL: Mark as REST API port to prevent SNMP discovery from deleting it
        if (!isset($entityData['port_descr_type'])) {
            $entityData['port_descr_type'] = 'rest-api';
        }

        $validColumns = [
            'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifOperStatus', 'ifAdminStatus',
            'ifSpeed', 'ifHighSpeed', 'ifMtu', 'ifPhysAddress', 'ifLastChange', 'ifVlan', 'ifTrunk',
            'disabled', 'deleted', 'ignore', 'port_descr_type', 'pagpOperationMode', 'pagpPortState', 'pagpPartnerDeviceId',
            'pagpPartnerLearnMethod', 'pagpPartnerIfIndex', 'pagpPartnerGroupIfIndex', 'pagpPartnerDeviceName',
            'pagpEthcOperationMode', 'pagpDeviceId', 'pagpGroupIfIndex'
        ];

        $filteredData = array_intersect_key($entityData, array_flip($validColumns));

        // Store extra fields as metrics
        $extraFields = array_diff_key($entityData, array_flip($validColumns));
        if (!empty($extraFields)) {
            foreach ($extraFields as $key => $value) {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $identifier . '.' . $key,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            }
        }

        DB::table('ports')->updateOrInsert(
            [
                'device_id' => $deviceId,
                'ifDescr' => $filteredData['ifDescr'],
            ],
            $filteredData
        );
    }

    /**
     * Apply entPhysical entity data
     */
    protected static function applyEntPhysicalEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        $identifier = $entityData['entPhysicalName'] ?? $entityData['name'] ?? null;
        if (!$identifier) {
            Log::warning("No identifier found for entPhysical entity", ['entity_data' => $entityData]);
            return;
        }

        // SMART ROUTING: If this is an ethernet port, route to ports table instead
        $class = $entityData['entPhysicalClass'] ?? null;
        if (in_array($class, ['eth_port', 'port', 'ethernet'])) {
            $status = $entityData['status'] ?? $entityData['sensor_value'] ?? 'up';
            $operStatus = self::mapStatusToIfOperStatus($status);

            $portData = [
                'ifDescr' => $identifier,
                'ifName' => $identifier,
                'ifAlias' => $entityData['entPhysicalDescr'] ?? null,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => $operStatus,
                'ifAdminStatus' => 'up',
                'disabled' => in_array($operStatus, ['down', 'lowerLayerDown', 'notPresent']) ? 1 : 0,
            ];

            $portData = array_filter($portData, fn($v) => $v !== null);
            self::applyPortEntity($deviceId, $portData, $endpoint);
            return;
        }

        $validColumns = [
            'entPhysicalIndex', 'entPhysicalDescr', 'entPhysicalClass', 'entPhysicalName',
            'entPhysicalHardwareRev', 'entPhysicalFirmwareRev', 'entPhysicalSoftwareRev',
            'entPhysicalAlias', 'entPhysicalAssetID', 'entPhysicalIsFRU', 'entPhysicalModelName',
            'entPhysicalVendorType', 'entPhysicalSerialNum', 'entPhysicalContainedIn',
            'entPhysicalParentRelPos', 'entPhysicalMfgName', 'ifIndex'
        ];

        $filteredData = array_intersect_key($entityData, array_flip($validColumns));
        $filteredData['entPhysicalName'] = $identifier;

        // CRITICAL: Never set entPhysicalIndex manually - it's an auto-increment primary key
        // Remove it from filtered data to prevent issues
        unset($filteredData['entPhysicalIndex']);

        // CRITICAL: Set default hierarchy fields to prevent infinite recursion on inventory page
        // If not provided, set entPhysicalContainedIn to 0 (root level)
        if (!isset($filteredData['entPhysicalContainedIn'])) {
            $filteredData['entPhysicalContainedIn'] = 0;
        }

        // CRITICAL: If entPhysicalContainedIn equals entPhysicalIndex, reset to 0 (prevents self-reference)
        if (isset($filteredData['entPhysicalContainedIn']) &&
            isset($filteredData['entPhysicalIndex']) &&
            $filteredData['entPhysicalContainedIn'] == $filteredData['entPhysicalIndex']) {
            $filteredData['entPhysicalContainedIn'] = 0;
        }

        // Store extra fields as metrics
        $extraFields = array_diff_key($entityData, array_flip($validColumns));
        if (!empty($extraFields)) {
            foreach ($extraFields as $key => $value) {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $identifier . '.' . $key,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            }
        }

        // Get or create entPhysical record
        $existing = DB::table('entPhysical')
            ->where('device_id', $deviceId)
            ->where('entPhysicalName', $identifier)
            ->first();

        if ($existing) {
            // Update existing record
            DB::table('entPhysical')
                ->where('device_id', $deviceId)
                ->where('entPhysicalName', $identifier)
                ->update($filteredData);
        } else {
            // CRITICAL DEBUG: Log what we're about to insert
            Log::warning("CREATING entPhysical record - THIS SHOULD NOT HAPPEN FOR PURESTORAGE", [
                'device_id' => $deviceId,
                'identifier' => $identifier,
                'entPhysicalClass' => $filteredData['entPhysicalClass'] ?? 'unknown',
                'entPhysicalIndex' => $filteredData['entPhysicalIndex'] ?? 'not set',
                'entPhysicalContainedIn' => $filteredData['entPhysicalContainedIn'] ?? 'not set',
                'endpoint' => $endpoint->path ?? 'unknown',
                'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);

            // Insert new record - let entPhysicalIndex auto-increment
            DB::table('entPhysical')->insert(array_merge([
                'device_id' => $deviceId,
            ], $filteredData));
        }
    }

    /**
     * Apply sensor entity data
     */
    protected static function applySensorEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        $identifier = $entityData['sensor_descr'] ?? $entityData['name'] ?? null;
        if (!$identifier) {
            Log::warning("No identifier found for sensor entity", ['entity_data' => $entityData]);
            return;
        }

        $validColumns = [
            'sensor_deleted', 'sensor_class', 'poller_type', 'sensor_oid', 'sensor_index',
            'sensor_type', 'sensor_descr', 'group', 'sensor_divisor', 'sensor_multiplier',
            'sensor_current', 'sensor_limit', 'sensor_limit_warn', 'sensor_limit_low',
            'sensor_limit_low_warn', 'sensor_alert', 'sensor_custom', 'entPhysicalIndex',
            'entPhysicalIndex_measured', 'sensor_prev', 'user_func', 'state_name',
            'sensor_info', 'lastupdate', 'sensor_polled'
        ];

        $filteredData = array_intersect_key($entityData, array_flip($validColumns));
        $filteredData['sensor_descr'] = $identifier;

        // Store extra fields as metrics
        $extraFields = array_diff_key($entityData, array_flip($validColumns));
        if (!empty($extraFields)) {
            foreach ($extraFields as $key => $value) {
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $identifier . '.' . $key,
                        'endpoint_name' => $endpoint->path,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            }
        }

        DB::table('sensors')->updateOrInsert(
            [
                'device_id' => $deviceId,
                'sensor_descr' => $identifier,
            ],
            $filteredData
        );
    }

    /**
     * Apply metrics entity data
     */
    protected static function applyMetricsEntity(int $deviceId, array $entityData, RestApiEndpoint $endpoint): void
    {
        foreach ($entityData as $key => $value) {
            RestApiMetric::updateOrCreate(
                [
                    'device_id' => $deviceId,
                    'metric_key' => $key,
                    'endpoint_name' => $endpoint->path,
                ],
                [
                    'metric_value' => (string) $value,
                    'last_updated' => now(),
                ]
            );
        }
    }

    /**
     * Map API status values to LibreNMS ifOperStatus
     */
    protected static function mapStatusToIfOperStatus(string $status): string
    {
        return match (strtolower($status)) {
            'up', 'ok', 'healthy', 'active', 'online', 'ready' => 'up',
            'down', 'failed', 'error', 'offline' => 'down',
            'disabled', 'not_installed', 'unused' => 'lowerLayerDown',
            'testing', 'initializing' => 'testing',
            default => 'unknown',
        };
    }
}
