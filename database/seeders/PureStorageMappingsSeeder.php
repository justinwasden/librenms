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
                'metric_name' => 'name',
                'resource_type' => 'array',
                'librenms_table' => 'devices',
                'librenms_field' => 'sysName',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Array name',
            ],
            [
                'metric_name' => 'version',
                'resource_type' => 'array',
                'librenms_table' => 'devices',
                'librenms_field' => 'version',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Purity version',
            ],
            [
                'metric_name' => 'os',
                'resource_type' => 'array',
                'librenms_table' => 'devices',
                'librenms_field' => 'version',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'OS version',
            ],

            // =================================================================
            // STORAGE TABLE - Volumes/LUNs
            // =================================================================
            [
                'metric_name' => 'provisioned',
                'resource_type' => 'volume',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Volume provisioned size',
            ],
            [
                'metric_name' => 'size',
                'resource_type' => 'volume',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Volume size',
            ],
            [
                'metric_name' => 'total_physical',
                'resource_type' => 'volume',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_used',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Physical space used',
            ],
            [
                'metric_name' => 'total_used',
                'resource_type' => 'volume',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_used',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Total used space',
            ],

            // =================================================================
            // PORTS TABLE - Network Interfaces
            // =================================================================
            [
                'metric_name' => 'name',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifName',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Port name',
            ],
            [
                'metric_name' => 'enabled',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifAdminStatus',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Port admin status',
            ],
            [
                'metric_name' => 'speed',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifSpeed',
                'data_type' => 'numeric',
                'unit' => 'bps',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Port speed',
            ],
            [
                'metric_name' => 'address',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifPhysAddress',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'MAC/Physical address',
            ],
            [
                'metric_name' => 'mac_address',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifPhysAddress',
                'data_type' => 'string',
                'unit' => null,
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'MAC address',
            ],
            [
                'metric_name' => 'mtu',
                'resource_type' => 'port',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifMtu',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'MTU size',
            ],

            // =================================================================
            // SENSORS TABLE - Performance Metrics
            // =================================================================
            [
                'metric_name' => 'read_bytes_per_sec',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'bytes/sec',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Read bandwidth',
            ],
            [
                'metric_name' => 'write_bytes_per_sec',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'bytes/sec',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Write bandwidth',
            ],
            [
                'metric_name' => 'reads_per_sec',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'iops',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Read IOPS',
            ],
            [
                'metric_name' => 'writes_per_sec',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'iops',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Write IOPS',
            ],
            [
                'metric_name' => 'usec_per_read_op',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'microseconds',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Read latency',
            ],
            [
                'metric_name' => 'usec_per_write_op',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'microseconds',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Write latency',
            ],

            // =================================================================
            // SENSORS TABLE - Hardware Sensors
            // =================================================================
            [
                'metric_name' => 'temperature',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'celsius',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Temperature',
            ],
            [
                'metric_name' => 'voltage',
                'resource_type' => 'sensor',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'volts',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'Voltage',
            ],

            // =================================================================
            // SENSORS TABLE - Network Performance (Port Stats as Sensors)
            // =================================================================
            [
                'metric_name' => 'received_bytes_per_sec',
                'resource_type' => 'port',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'bytes/sec',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'RX bandwidth',
            ],
            [
                'metric_name' => 'transmitted_bytes_per_sec',
                'resource_type' => 'port',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'bytes/sec',
                'vendor' => 'Pure Storage',
                'os' => 'purestorage',
                'enabled' => true,
                'description' => 'TX bandwidth',
            ],
        ];

        // Insert mappings with proper timestamps
        foreach ($mappings as $mapping) {
            DB::table('metric_field_mappings')->updateOrInsert(
                [
                    'metric_name' => $mapping['metric_name'],
                    'resource_type' => $mapping['resource_type'],
                    'vendor' => $mapping['vendor'],
                    'os' => $mapping['os'],
                ],
                array_merge($mapping, [
                    'created_at' => now(),
                    'updated_at' => now(),
                    'auto_learned' => false, // These are manually defined, not auto-learned
                ])
            );
        }

        $this->command->info('✓ Created ' . count($mappings) . ' Pure Storage metric mappings');
        
        // Display summary
        $summary = DB::table('metric_field_mappings')
            ->where('os', 'purestorage')
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
