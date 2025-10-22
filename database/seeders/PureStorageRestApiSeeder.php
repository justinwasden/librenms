<?php

namespace Database\Seeders;

use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use Illuminate\Database\Seeder;

class PureStorageRestApiSeeder extends Seeder
{
    /**
     * Run the seeder to create Pure Storage REST API endpoints
     * 
     * Usage:
     *   php artisan db:seed --class=PureStorageRestApiSeeder
     *   
     * Or in code:
     *   $this->call(PureStorageRestApiSeeder::class);
     *   
     * Note: This creates the endpoint templates. You still need to:
     *   1. Create a RestApiConnection manually (device_id, base_url, credential)
     *   2. Then run this seeder to add endpoints to that connection
     */
    public function run(): void
    {
        $this->createPureStorageEndpoints();
    }

    private function createPureStorageEndpoints(): void
    {
        // Get the first Pure Storage connection (you may need to adjust this)
        // Or pass connection_id as parameter
        $connection = RestApiConnection::first();
        
        if (!$connection) {
            $this->command->info('No REST API connections found. Create one first.');
            return;
        }

        $this->command->info("Creating Pure Storage endpoints for connection: {$connection->name}");

        // 1. Array Information
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/arrays'],
            [
                'name' => 'Array Information',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'array',
                'enabled' => true,
                'template_response_mapping' => [
                    'devices.hostname' => '$.items[*].name',
                    'devices.sysName' => '$.items[*].name',
                    'devices.version' => '$.items[*].version',
                    'devices.os' => '$.items[*].os',
                    'devices.hardware' => '$.items[*].model',
                    'devices.serial' => '$.items[*].id',
                    'devices.location' => '$.items[*].time_zone',
                ],
            ]
        );
        $this->command->info('✓ /arrays');

        // 2. Volumes
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/volumes'],
            [
                'name' => 'Storage Volumes',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'volume',
                'enabled' => true,
                'template_response_mapping' => [
                    'storage.storage_descr' => '$.items[*].name',
                    'storage.storage_size' => '$.items[*].space.total_provisioned',
                    'storage.storage_used' => '$.items[*].space.total_physical',
                    'storage.storage_shared' => '$.items[*].space.snapshots',
                    'storage.block_size' => '$.items[*].block_size',
                    'storage.serial' => '$.items[*].serial',
                ],
            ]
        );
        $this->command->info('✓ /volumes');

        // 3. Volume Performance
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/volumes/performance'],
            [
                'name' => 'Volume Performance',
                'http_method' => 'GET',
                'poll_interval' => 60,
                'resource_type' => 'volume_performance',
                'enabled' => true,
                'template_response_mapping' => [
                    'rest_api_metrics.endpoint' => 'volumes/performance',
                    'rest_api_metrics.volume_name' => '$.items[*].name',
                    'rest_api_metrics.read_bytes_per_sec' => '$.items[*].read_bytes_per_sec',
                    'rest_api_metrics.write_bytes_per_sec' => '$.items[*].write_bytes_per_sec',
                    'rest_api_metrics.reads_per_sec' => '$.items[*].reads_per_sec',
                    'rest_api_metrics.writes_per_sec' => '$.items[*].writes_per_sec',
                    'rest_api_metrics.usec_per_read_op' => '$.items[*].usec_per_read_op',
                    'rest_api_metrics.usec_per_write_op' => '$.items[*].usec_per_write_op',
                    'rest_api_metrics.queue_usec_per_read_op' => '$.items[*].queue_usec_per_read_op',
                    'rest_api_metrics.queue_usec_per_write_op' => '$.items[*].queue_usec_per_write_op',
                    'rest_api_metrics.bytes_per_read' => '$.items[*].bytes_per_read',
                    'rest_api_metrics.bytes_per_write' => '$.items[*].bytes_per_write',
                ],
            ]
        );
        $this->command->info('✓ /volumes/performance');

        // 4. Network Interfaces
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/network-interfaces'],
            [
                'name' => 'Network Interfaces',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'interface',
                'enabled' => true,
                'template_response_mapping' => [
                    'ports.ifName' => '$.items[*].name',
                    'ports.ifDescr' => '$.items[*].services[0]',
                    'ports.ifType' => '$.items[*].interface_type',
                    'ports.ifSpeed' => '$.items[*].speed',
                    'ports.ifPhysAddress' => '$.items[*].eth.mac_address',
                    'ports.ifAdminStatus' => '$.items[*].enabled',
                    'ports.ifOperStatus' => '$.items[*].enabled',
                    'ports.ifMtu' => '$.items[*].eth.mtu',
                    'ports.ifAlias' => '$.items[*].eth.address',
                ],
            ]
        );
        $this->command->info('✓ /network-interfaces');

        // 5. Network Interface Performance
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/network-interfaces/performance'],
            [
                'name' => 'Interface Performance',
                'http_method' => 'GET',
                'poll_interval' => 60,
                'resource_type' => 'interface_performance',
                'enabled' => true,
                'template_response_mapping' => [
                    'ports_statistics.ifInOctets' => '$.items[*].eth.received_bytes_per_sec',
                    'ports_statistics.ifOutOctets' => '$.items[*].eth.transmitted_bytes_per_sec',
                    'ports_statistics.ifInUcastPkts' => '$.items[*].eth.received_packets_per_sec',
                    'ports_statistics.ifOutUcastPkts' => '$.items[*].eth.transmitted_packets_per_sec',
                    'ports_statistics.ifInErrors' => '$.items[*].eth.received_errors_per_sec',
                    'ports_statistics.ifOutErrors' => '$.items[*].eth.transmitted_errors_per_sec',
                ],
            ]
        );
        $this->command->info('✓ /network-interfaces/performance');

        // 6. Controllers
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/controllers'],
            [
                'name' => 'Controllers',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'controller',
                'enabled' => true,
                'template_response_mapping' => [
                    'entPhysical.entPhysicalName' => '$.items[*].name',
                    'entPhysical.entPhysicalModelName' => '$.items[*].model',
                    'entPhysical.entPhysicalFirmwareRev' => '$.items[*].version',
                    'entPhysical.entPhysicalSerialNum' => '$.items[*].serial',
                ],
            ]
        );
        $this->command->info('✓ /controllers');

        // 7. Hardware Components
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/hardware'],
            [
                'name' => 'Hardware Components',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'hardware',
                'enabled' => true,
                'template_response_mapping' => [
                    'entPhysical.entPhysicalName' => '$.items[*].name',
                    'entPhysical.entPhysicalClass' => '$.items[*].type',
                    'entPhysical.entPhysicalModelName' => '$.items[*].model',
                    'entPhysical.entPhysicalSerialNum' => '$.items[*].serial',
                ],
            ]
        );
        $this->command->info('✓ /hardware');

        // 8. Drives
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/drives'],
            [
                'name' => 'Drives',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'drive',
                'enabled' => true,
                'template_response_mapping' => [
                    'storage.storage_descr' => '$.items[*].name',
                    'storage.storage_type' => '$.items[*].type',
                    'storage.storage_size' => '$.items[*].capacity',
                    'storage.serial' => '$.items[*].serial',
                ],
            ]
        );
        $this->command->info('✓ /drives');

        // 9. Array Connections
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/array-connections'],
            [
                'name' => 'Array Connections',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'connection',
                'enabled' => true,
                'template_response_mapping' => [
                    'links.local_port' => '$.items[*].local_port.name',
                    'links.remote_port' => '$.items[*].remote_port.name',
                    'links.remote_hostname' => '$.items[*].remote_array.name',
                    'links.link_transport' => '$.items[*].replication_transport',
                ],
            ]
        );
        $this->command->info('✓ /array-connections');

        // 10. Subnets
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/subnets'],
            [
                'name' => 'Subnets',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'subnet',
                'enabled' => true,
                'template_response_mapping' => [
                    'ipv4_networks.ipv4_network' => '$.items[*].prefix',
                    'ipv4_networks.vlan_id' => '$.items[*].vlan',
                    'ipv4_networks.network_descr' => '$.items[*].name',
                ],
            ]
        );
        $this->command->info('✓ /subnets');

        // 11. Port Details (Transceivers)
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/network-interfaces/port-details'],
            [
                'name' => 'Port Details',
                'http_method' => 'GET',
                'poll_interval' => 300,
                'resource_type' => 'port_details',
                'enabled' => false, // Disabled by default - enable if needed
                'template_response_mapping' => [
                    'transceivers.transceiver_name' => '$.items[*].name',
                    'transceivers.vendor_name' => '$.items[*].static.vendor_name',
                    'transceivers.vendor_sn' => '$.items[*].static.vendor_serial_number',
                    'transceivers.wavelength' => '$.items[*].static.wavelength',
                ],
            ]
        );
        $this->command->info('✓ /network-interfaces/port-details (disabled by default)');

        // 12. Array Performance
        RestApiEndpoint::updateOrCreate(
            ['connection_id' => $connection->id, 'path' => '/arrays/performance'],
            [
                'name' => 'Array Performance',
                'http_method' => 'GET',
                'poll_interval' => 60,
                'resource_type' => 'performance',
                'enabled' => false, // Disabled by default - enable if needed
                'template_response_mapping' => [
                    // Performance metrics would go here
                    // These would need custom sensor handling
                ],
            ]
        );
        $this->command->info('✓ /arrays/performance (disabled by default)');

        $this->command->info("\n✅ Pure Storage endpoints created successfully!");
        $this->command->info("All endpoints are now ready to poll. Mappings specify exactly which API fields map to which database fields.");
        $this->command->info("To edit mappings, update the template_response_mapping in rest_api_endpoints table.");
    }
}
