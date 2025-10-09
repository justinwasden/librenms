<?php

namespace Database\Seeders;

use App\Models\MetricFieldMapping;
use Illuminate\Database\Seeder;

class PureStorageMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $mappings = [
            // Pure Storage Volume/Storage Mappings
            [
                'metric_name' => 'space_total_physical',
                'resource_type' => 'storage',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'description' => 'Pure Storage volume total physical capacity',
            ],
            [
                'metric_name' => 'space_total_provisioned',
                'resource_type' => 'storage',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_size',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'description' => 'Pure Storage volume total provisioned capacity',
            ],
            [
                'metric_name' => 'space_total_used',
                'resource_type' => 'storage',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_used',
                'data_type' => 'numeric',
                'unit' => 'bytes',
                'description' => 'Pure Storage volume used space',
            ],
            [
                'metric_name' => 'name',
                'resource_type' => 'storage',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'storage',
                'librenms_field' => 'storage_descr',
                'data_type' => 'string',
                'description' => 'Pure Storage volume name',
            ],
            
            // Pure Storage Array/Device Mappings
            [
                'metric_name' => 'version',
                'resource_type' => 'device',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'devices',
                'librenms_field' => 'version',
                'data_type' => 'string',
                'description' => 'Pure Storage array firmware version',
            ],
            [
                'metric_name' => 'model',
                'resource_type' => 'device',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'devices',
                'librenms_field' => 'hardware',
                'data_type' => 'string',
                'description' => 'Pure Storage array model',
            ],
            
            // Pure Storage Controller/Sensor Mappings
            [
                'metric_name' => 'status',
                'resource_type' => 'sensor',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'string',
                'description' => 'Pure Storage controller status',
            ],
            [
                'metric_name' => 'temperature',
                'resource_type' => 'sensor',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'sensors',
                'librenms_field' => 'sensor_current',
                'data_type' => 'numeric',
                'unit' => 'celsius',
                'description' => 'Pure Storage temperature sensor',
            ],
            
            // Pure Storage Network/Port Mappings
            [
                'metric_name' => 'speed',
                'resource_type' => 'port',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifSpeed',
                'data_type' => 'numeric',
                'unit' => 'gbps', // Store as Gbps, will convert in code
                'description' => 'Pure Storage network interface speed in Gbps',
            ],
            [
                'metric_name' => 'enabled',
                'resource_type' => 'port',
                'vendor' => 'Pure Storage',
                'librenms_table' => 'ports',
                'librenms_field' => 'ifAdminStatus',
                'data_type' => 'boolean',
                'description' => 'Pure Storage network interface enabled status',
            ],
        ];
        
        foreach ($mappings as $mapping) {
            MetricFieldMapping::updateOrCreate(
                [
                    'metric_name' => $mapping['metric_name'],
                    'resource_type' => $mapping['resource_type'],
                    'vendor' => $mapping['vendor'] ?? null,
                    'os' => $mapping['os'] ?? null,
                ],
                array_merge($mapping, [
                    'auto_learned' => false,
                    'enabled' => true,
                ])
            );
        }
        
        $this->command->info('Pure Storage metric mappings created successfully!');
    }
}
