<?php

namespace Database\Seeders;

use App\Models\RestApiEndpoint;
use App\Models\RestApiMapping;
use Illuminate\Database\Seeder;
use Log;

/**
 * Pure Storage REST API Mappings Seeder
 * 
 * Populates rest_api_mappings table with static field mappings for all Pure Storage endpoints
 * Using JSONPath extraction to map API response fields to LibreNMS database fields
 */
class PureStorageMappingsSeeder extends Seeder
{
    public function run(): void
    {
        Log::info("Seeding Pure Storage REST API mappings...");

        // Get all Pure Storage endpoints
        $endpoints = RestApiEndpoint::whereHas('connection', function($q) {
            $q->where('name', 'like', '%Pure%');
        })->get();

        if ($endpoints->isEmpty()) {
            Log::warning("No Pure Storage endpoints found");
            return;
        }

        foreach ($endpoints as $endpoint) {
            $this->seedEndpointMappings($endpoint);
        }

        Log::info("Pure Storage mappings seeded successfully");
    }

    /**
     * Seed mappings for a specific endpoint
     */
    protected function seedEndpointMappings(RestApiEndpoint $endpoint): void
    {
        $mappings = [];

        // Match endpoint by path
        $path = strtolower($endpoint->path);

        if (strpos($path, '/arrays') !== false && strpos($path, '/performance') === false) {
            $mappings = $this->getArrayInfoMappings();
        } elseif (strpos($path, '/controllers') !== false) {
            $mappings = $this->getControllersMappings();
        } elseif (strpos($path, '/volumes') !== false && strpos($path, '/performance') === false) {
            $mappings = $this->getVolumesMappings();
        } elseif (strpos($path, '/network-interfaces') !== false && strpos($path, '/performance') === false && strpos($path, 'port-details') === false) {
            $mappings = $this->getNetworkInterfacesMappings();
        } elseif (strpos($path, '/network-interfaces/performance') !== false) {
            $mappings = $this->getInterfacePerformanceMappings();
        } elseif (strpos($path, '/port-details') !== false) {
            $mappings = $this->getPortDetailsMappings();
        } elseif (strpos($path, '/arrays/performance') !== false) {
            $mappings = $this->getArrayPerformanceMappings();
        } elseif (strpos($path, '/volumes/performance') !== false) {
            $mappings = $this->getVolumePerformanceMappings();
        } elseif (strpos($path, '/hardware') !== false) {
            $mappings = $this->getHardwareMappings();
        } elseif (strpos($path, '/drives') !== false) {
            $mappings = $this->getDrivesMappings();
        } elseif (strpos($path, '/array-connections') !== false) {
            $mappings = $this->getArrayConnectionsMappings();
        } elseif (strpos($path, '/subnets') !== false) {
            $mappings = $this->getSubnetsMappings();
        } elseif (strpos($path, '/space') !== false) {
            $mappings = $this->getSpaceMappings();
        }

        // Insert mappings
        foreach ($mappings as $mapping) {
            RestApiMapping::updateOrCreate(
                [
                    'endpoint_id' => $endpoint->id,
                    'target_field' => $mapping['target_field'],
                ],
                $mapping
            );
        }

        Log::info("Seeded mappings for endpoint: {$endpoint->name}");
    }

    protected function getArrayInfoMappings(): array
    {
        return [
            ['target_table' => 'devices', 'target_field' => 'hostname', 'source_field' => '$.items[0].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'devices', 'target_field' => 'sysName', 'source_field' => '$.items[0].name'],
            ['target_table' => 'devices', 'target_field' => 'version', 'source_field' => '$.items[0].version'],
            ['target_table' => 'devices', 'target_field' => 'hardware', 'source_field' => '$.items[0].model'],
            ['target_table' => 'devices', 'target_field' => 'os', 'source_field' => '$.items[0].os'],
            ['target_table' => 'devices', 'target_field' => 'serial', 'source_field' => '$.items[0].id'],
        ];
    }

    protected function getControllersMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'status', 'source_field' => '$.items[*].status', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'name', 'source_field' => '$.items[*].name'],
            ['target_table' => 'sensors', 'target_field' => 'model', 'source_field' => '$.items[*].model'],
            ['target_table' => 'sensors', 'target_field' => 'version', 'source_field' => '$.items[*].version'],
            ['target_table' => 'sensors', 'target_field' => 'mode', 'source_field' => '$.items[*].mode'],
        ];
    }

    protected function getVolumesMappings(): array
    {
        return [
            ['target_table' => 'storage', 'target_field' => 'storage_descr', 'source_field' => '$.items[*].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'storage', 'target_field' => 'storage_size', 'source_field' => '$.items[*].space.total_provisioned', 'data_type' => 'integer'],
            ['target_table' => 'storage', 'target_field' => 'storage_used', 'source_field' => '$.items[*].space.total_physical', 'data_type' => 'integer'],
        ];
    }

    protected function getNetworkInterfacesMappings(): array
    {
        return [
            ['target_table' => 'ports', 'target_field' => 'ifName', 'source_field' => '$.items[*].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'ports', 'target_field' => 'ifDescr', 'source_field' => '$.items[*].services[0]'],
            ['target_table' => 'ports', 'target_field' => 'ifType', 'source_field' => '$.items[*].interface_type'],
            ['target_table' => 'ports', 'target_field' => 'ifSpeed', 'source_field' => '$.items[*].speed', 'data_type' => 'integer'],
            ['target_table' => 'ports', 'target_field' => 'ifPhysAddress', 'source_field' => '$.items[*].eth.mac_address'],
            ['target_table' => 'ports', 'target_field' => 'ifAdminStatus', 'source_field' => '$.items[*].enabled'],
            ['target_table' => 'ports', 'target_field' => 'ifMtu', 'source_field' => '$.items[*].eth.mtu', 'data_type' => 'integer'],
        ];
    }

    protected function getInterfacePerformanceMappings(): array
    {
        return [
            ['target_table' => 'ports', 'target_field' => 'ifName', 'source_field' => '$.items[*].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'ports', 'target_field' => 'ifInOctets', 'source_field' => '$.items[*].eth.received_bytes_per_sec', 'data_type' => 'integer'],
            ['target_table' => 'ports', 'target_field' => 'ifOutOctets', 'source_field' => '$.items[*].eth.transmitted_bytes_per_sec', 'data_type' => 'integer'],
        ];
    }

    protected function getPortDetailsMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'temperature', 'source_field' => '$.items[*].temperature[0].measurement', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'voltage', 'source_field' => '$.items[*].voltage[0].measurement'],
            ['target_table' => 'sensors', 'target_field' => 'tx_power', 'source_field' => '$.items[*].tx_power[0].measurement'],
            ['target_table' => 'sensors', 'target_field' => 'rx_power', 'source_field' => '$.items[*].rx_power[0].measurement'],
        ];
    }

    protected function getArrayPerformanceMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'read_bytes_per_sec', 'source_field' => '$.items[0].read_bytes_per_sec', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'write_bytes_per_sec', 'source_field' => '$.items[0].write_bytes_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'reads_per_sec', 'source_field' => '$.items[0].reads_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'writes_per_sec', 'source_field' => '$.items[0].writes_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'usec_per_read_op', 'source_field' => '$.items[0].usec_per_read_op'],
            ['target_table' => 'sensors', 'target_field' => 'usec_per_write_op', 'source_field' => '$.items[0].usec_per_write_op'],
        ];
    }

    protected function getVolumePerformanceMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'name', 'source_field' => '$.items[*].name', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'read_bytes_per_sec', 'source_field' => '$.items[*].read_bytes_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'write_bytes_per_sec', 'source_field' => '$.items[*].write_bytes_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'reads_per_sec', 'source_field' => '$.items[*].reads_per_sec'],
            ['target_table' => 'sensors', 'target_field' => 'writes_per_sec', 'source_field' => '$.items[*].writes_per_sec'],
        ];
    }

    protected function getHardwareMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'name', 'source_field' => '$.items[*].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'sensors', 'target_field' => 'temperature', 'source_field' => '$.items[*].temperature', 'data_type' => 'integer'],
            ['target_table' => 'sensors', 'target_field' => 'voltage', 'source_field' => '$.items[*].voltage', 'data_type' => 'float'],
            ['target_table' => 'sensors', 'target_field' => 'status', 'source_field' => '$.items[*].status'],
            ['target_table' => 'sensors', 'target_field' => 'type', 'source_field' => '$.items[*].type'],
        ];
    }

    protected function getDrivesMappings(): array
    {
        return [
            ['target_table' => 'storage', 'target_field' => 'storage_descr', 'source_field' => '$.items[*].name', 'is_identifier' => true, 'is_required' => true],
            ['target_table' => 'storage', 'target_field' => 'storage_size', 'source_field' => '$.items[*].capacity', 'data_type' => 'integer'],
            ['target_table' => 'storage', 'target_field' => 'component_type', 'source_field' => '$.items[*].type'],
        ];
    }

    protected function getArrayConnectionsMappings(): array
    {
        return [
            ['target_table' => 'links', 'target_field' => 'local_port', 'source_field' => '$.items[*].local_port', 'is_identifier' => true],
            ['target_table' => 'links', 'target_field' => 'remote_port', 'source_field' => '$.items[*].remote_port'],
            ['target_table' => 'links', 'target_field' => 'remote_hostname', 'source_field' => '$.items[*].name'],
            ['target_table' => 'links', 'target_field' => 'link_transport', 'source_field' => '$.items[*].replication_transport'],
            ['target_table' => 'links', 'target_field' => 'link_status', 'source_field' => '$.items[*].status'],
        ];
    }

    protected function getSubnetsMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'prefix', 'source_field' => '$.items[*].prefix', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'vlan', 'source_field' => '$.items[*].vlan'],
            ['target_table' => 'sensors', 'target_field' => 'name', 'source_field' => '$.items[*].name'],
        ];
    }

    protected function getSpaceMappings(): array
    {
        return [
            ['target_table' => 'sensors', 'target_field' => 'total_provisioned', 'source_field' => '$.total_provisioned', 'is_identifier' => true],
            ['target_table' => 'sensors', 'target_field' => 'total_used', 'source_field' => '$.total_used'],
            ['target_table' => 'sensors', 'target_field' => 'data_reduction', 'source_field' => '$.data_reduction'],
            ['target_table' => 'sensors', 'target_field' => 'total_reduction', 'source_field' => '$.total_reduction'],
            ['target_table' => 'sensors', 'target_field' => 'replication', 'source_field' => '$.replication'],
            ['target_table' => 'sensors', 'target_field' => 'snapshots', 'source_field' => '$.snapshots'],
        ];
    }
}
