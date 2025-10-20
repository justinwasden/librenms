<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PureStorageMappingsSeeder extends Seeder
{
    /**
     * Seed Pure Storage metric field mappings
     * Maps Pure Storage API fields to native LibreNMS tables
     */
    public function run(): void
    {
        $mappings = [
            // =================================================================
            // DEVICES TABLE - Array-level information
            // =================================================================
            [
                'api_field_name' => 'name',
                'librenms_table' => 'devices',
                'librenms_field' => 'sysName',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'version',
                'librenms_table' => 'devices',
                'librenms_field' => 'version',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'os',
                'librenms_table' => 'devices',
                'librenms_field' => 'version',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // STORAGE TABLE - Volumes/LUNs
            // =================================================================
            [
                'api_field_name' => 'provisioned',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'unit' => 'bytes',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'size',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'unit' => 'bytes',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'total_physical',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_used',
                'unit' => 'bytes',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'total_used',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_used',
                'unit' => 'bytes',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // ENTPHYSICAL TABLE - Controllers
            // =================================================================
            [
                'api_field_name' => 'controller_name',
                'librenms_table' => 'entPhysical',
                'librenms_field' => 'entPhysicalDescr',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'controller_model',
                'librenms_table' => 'entPhysical',
                'librenms_field' => 'entPhysicalModelName',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'controller_status',
                'librenms_table' => 'entPhysical',
                'librenms_field' => 'entPhysicalOperStatus',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'controller_mode',
                'librenms_table' => 'entPhysical',
                'librenms_field' => 'entPhysicalClass',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'purity_version',
                'librenms_table' => 'entPhysical',
                'librenms_field' => 'entPhysicalHardwareRev',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // PORTS TABLE - Network Interfaces (Basic Info)
            // =================================================================
            [
                'api_field_name' => 'name',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifName',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'enabled',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifAdminStatus',
                'unit' => null,
                'transform' => 'boolean_to_updown',
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'speed',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifSpeed',
                'unit' => 'bps',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'interface_type',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifType',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // PORTS TABLE - Network Interfaces (Nested eth fields)
            // =================================================================
            [
                'api_field_name' => 'eth_address',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifAlias',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_mac_address',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifPhysAddress',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_mtu',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifMtu',
                'unit' => 'bytes',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_vlan',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifVlan',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_subtype',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifDescr',
                'unit' => null,
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // SENSORS TABLE - Performance Metrics
            // =================================================================
            [
                'api_field_name' => 'read_bytes_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'bytes/sec',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'write_bytes_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'bytes/sec',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'reads_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'iops',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'writes_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'iops',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'usec_per_read_op',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'microseconds',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'usec_per_write_op',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'microseconds',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // SENSORS TABLE - Hardware Sensors
            // =================================================================
            [
                'api_field_name' => 'temperature',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'celsius',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'voltage',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'volts',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],

            // =================================================================
            // SENSORS TABLE - Network Performance (Port Stats as Sensors)
            // =================================================================
            [
                'api_field_name' => 'eth_received_bytes_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'bytes/sec',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_transmitted_bytes_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'bytes/sec',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_received_packets_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'pps',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_transmitted_packets_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'pps',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
            [
                'api_field_name' => 'eth_total_errors_per_sec',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'unit' => 'errors/sec',
                'transform' => null,
                'enabled' => true,
                'user_created' => false,
            ],
        ];

        // Insert mappings with proper timestamps
        foreach ($mappings as $mapping) {
            DB::table('rest_api_metric_field_mappings')->updateOrInsert(
                [
                    'api_field_name' => $mapping['api_field_name'],
                    'librenms_table' => $mapping['librenms_table'],
                    'librenms_field' => $mapping['librenms_field'],
                ],
                array_merge($mapping, [
                    'created_at' => now(),
                    'updated_at' => now(),
                    'device_id' => null, // Global mappings
                    'confidence_score' => 1.0, // Manual mappings have highest confidence
                ])
            );
        }

        $this->command->info('✓ Created ' . count($mappings) . ' Pure Storage metric mappings');
        
        // Display summary
        $summary = DB::table('rest_api_metric_field_mappings')
            ->select('librenms_table', DB::raw('COUNT(*) as count'))
            ->groupBy('librenms_table')
            ->get();
        
        $this->command->info('');
        $this->command->info('Mappings by table:');
        foreach ($summary as $row) {
            $this->command->info("  {$row->librenms_table}: {$row->count}");
        }
    }
}
