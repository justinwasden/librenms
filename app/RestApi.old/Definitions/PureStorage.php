<?php
namespace App\Devices\Definitions;

class PureStorage
{

    public static function endpoints(): array
    {
        return [
            '/api/2.26/arrays',
            '/api/2.26/alerts',
            '/api/2.26/controllers',
            '/api/2.26/volumes',
            '/api/2.26/hosts',
            '/api/2.26/hardware',
            '/api/2.26/drives',
            '/api/2.26/network-interfaces',
            '/api/2.26/arrays/performance',
            '/api/2.26/volumes/performance',
        ];
    }


    public static function mappings(): array
    {
        return [
            '/api/2.26/arrays' => [
                'capacity' => 'items.capacity',
                'total_physical' => 'items.space.total_physical',
                'total_used' => 'items.space.total_used',
                'total_provisioned' => 'items.space.total_provisioned',
                'data_reduction' => 'items.space.data_reduction',
                'total_reduction' => 'items.space.total_reduction',
                'unique' => 'items.space.unique',
                'shared' => 'items.space.shared',
                'snapshots' => 'items.space.snapshots',
                'name' => 'items.name',
                'version' => 'items.version',
                'os' => 'items.os',
            ],
            '/api/2.26/alerts' => [
                'alert_id' => 'items.id',
                'alert_state' => 'items.state',
                'alert_code' => 'items.code',
                'alert_severity' => 'items.severity',
                'alert_created' => 'items.created',
                'alert_issue' => 'items.issue',
            ],
            '/api/2.26/controllers' => [
                'controller_name' => 'items.name',
                'controller_model' => 'items.model',
                'controller_status' => 'items.status',
                'controller_mode' => 'items.mode',
                'purity_version' => 'items.version',
            ],
            '/api/2.26/volumes' => [
                'volume_name' => 'items.name',
                'volume_size' => 'items.total_physical',
                'volume_provisioned' => 'items.total_provisioned',
                'volume_snapshots' => 'items.snapshots',
                'volume_data_reduction' => 'items.total_reduction',
                'volume_connections' => 'items.connection_count',
                'volume_group' => 'items.volume_group.name',
                'volume_pod' => 'items.pod.name',
            ],
            '/api/2.26/ports' => [
                'interface_name' => 'items.name',
                'interface_ip_address' => 'items.eth.address',
                'interface_mac_address' => 'items.eth.mac_address',
                'interface_status' => 'items.enabled',
                'interface_speed' => 'items.speed',
                'interface_type' => 'items.type',
                'interface_services' => 'items.services',
            ],
            '/api/2.26/hosts' => [
                'host_name' => 'items.name',
                'host_group' => 'items.host_group.name',
                'host_connections' => 'items.connection_count',
                'host_connection_status' => 'items.port_connectivity.status',
                'host_connection_details' => 'items.port_connectivity.details',
                'host_totalspace' => 'items.space.total_physical',
                'host_provisioned_space' => 'items.space.total_provisioned',
                'host_used_space' => 'items.space.total_used',
                'host_total_reduction' => 'items.space.total_reduction',
            ],
            '/api/2.26/performance' => [
                'array_name' => 'items.name',
                'array_read_bytes_per_sec' => 'items.read_bytes_per_sec',
                'array_write_bytes_per_sec' => 'items.write_bytes_per_sec',
                'array_usec_per_read_op' => 'items.usec_per_read_op',
                'array_usec_per_write_op' => 'items.usec_per_write_op',
                'array_reads_per_sec' => 'items.reads_per_sec',
                'array_writes_per_sec' => 'items.writes_per_sec',
                'array_queue_usec_per_read_op' => 'items.queue_usec_per_read_op',
                'array_queue_usec_per_write_op' => 'items.queue_usec_per_write_op',
                'array_bytes_per_read' => 'items.bytes_per_read',
                'array_bytes_per_write' => 'items.bytes_per_write',
            ],
            '/api/2.26/performance-by-array' => [
                'volume_name' => 'items.name',
                'volume_read_bytes_per_sec' => 'items.read_bytes_per_sec',
                'volume_write_bytes_per_sec' => 'items.write_bytes_per_sec',
                'volume_usec_per_read_op' => 'items.usec_per_read_op',
                'volume_usec_per_write_op' => 'items.usec_per_write_op',
                'volume_reads_per_sec' => 'items.reads_per_sec',
                'volume_writes_per_sec' => 'items.writes_per_sec',
                'volume_queue_usec_per_read_op' => 'items.queue_usec_per_read_op',
                'volume_queue_usec_per_write_op' => 'items.queue_usec_per_write_op',
                'volume_bytes_per_read' => 'items.bytes_per_read',
                'volume_bytes_per_write' => 'items.bytes_per_write',
            ],
        ];
    }
}
