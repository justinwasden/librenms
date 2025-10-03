#!/usr/bin/env php
<?php

/**
 * Script to update PureStorage API endpoint configurations
 * Run: php scripts/update_purestorage_endpoints.php
 */

require __DIR__.'/../bootstrap/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RestApiEndpoint;
use Illuminate\Support\Facades\DB;

echo "Updating PureStorage API endpoint configurations...\n\n";

// Array Info Endpoint
$arrayEndpoint = RestApiEndpoint::where('name', 'Array Info')->first();
if ($arrayEndpoint) {
    $arrayEndpoint->update([
        'resource_type' => 'array',
        'resource_id_path' => 'id',
        'resource_name_path' => 'id',
        'metric_map' => [
            'id' => 'id',
            'capacity' => 'capacity',
            'parity' => 'parity',
            'space.data_reduction' => 'space.data_reduction',
            'space.shared' => 'space.shared',
            'space.snapshots' => 'space.snapshots',
            'space.system' => 'space.system',
            'space.thin_provisioning' => 'space.thin_provisioning',
            'space.total_physical' => 'space.total_physical',
            'space.total_used' => 'space.total_used',
            'space.total_provisioned' => 'space.total_provisioned',
            'space.total_reduction' => 'space.total_reduction',
            'space.unique' => 'space.unique',
            'space.virtual' => 'space.virtual',
        ]
    ]);
    echo "✓ Updated Array Info endpoint\n";
}

// Controllers Status Endpoint
$controllersEndpoint = RestApiEndpoint::where('name', 'Controllers Status')->first();
if ($controllersEndpoint) {
    $controllersEndpoint->update([
        'resource_type' => 'controller',
        'resource_id_path' => 'name',
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'type' => 'type',
            'model' => 'model',
            'status' => 'status',
            'mode' => 'mode',
            'version' => 'version',
        ]
    ]);
    echo "✓ Updated Controllers Status endpoint\n";
}

// Volumes Info Endpoint
$volumesEndpoint = RestApiEndpoint::where('name', 'Volumes Info')->first();
if ($volumesEndpoint) {
    $volumesEndpoint->update([
        'resource_type' => 'volume',
        'resource_id_path' => 'id',
        'resource_name_path' => 'name',
        'metric_map' => [
            'id' => 'id',
            'name' => 'name',
            'serial' => 'serial',
            'created' => 'created',
            'provisioned' => 'provisioned',
            'destroyed' => 'destroyed',
            'host_encryption_key_status' => 'host_encryption_key_status',
            'requested_promotion_state' => 'requested_promotion_state',
            'promotion_status' => 'promotion_status',
            'space.data_reduction' => 'space.data_reduction',
            'space.snapshots' => 'space.snapshots',
            'space.thin_provisioning' => 'space.thin_provisioning',
            'space.total_physical' => 'space.total_physical',
            'space.total_used' => 'space.total_used',
            'space.total_provisioned' => 'space.total_provisioned',
            'space.total_reduction' => 'space.total_reduction',
            'space.unique' => 'space.unique',
            'space.virtual' => 'space.virtual',
        ]
    ]);
    echo "✓ Updated Volumes Info endpoint\n";
}

// Network Interfaces Endpoint
$interfacesEndpoint = RestApiEndpoint::where('name', 'Network Interfaces')->first();
if ($interfacesEndpoint) {
    $interfacesEndpoint->update([
        'resource_type' => 'interface',
        'resource_id_path' => 'name',
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'enabled' => 'enabled',
            'interface_type' => 'interface_type',
            'services' => 'services',
            'speed' => 'speed',
            'eth.address' => 'eth.address',
            'eth.gateway' => 'eth.gateway',
            'eth.mac_address' => 'eth.mac_address',
            'eth.mtu' => 'eth.mtu',
            'eth.netmask' => 'eth.netmask',
            'eth.subtype' => 'eth.subtype',
        ]
    ]);
    echo "✓ Updated Network Interfaces endpoint\n";
}

// Hosts Endpoint
$hostsEndpoint = RestApiEndpoint::where('name', 'Hosts')->first();
if ($hostsEndpoint) {
    $hostsEndpoint->update([
        'resource_type' => 'host',
        'resource_id_path' => 'name',
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'is_local' => 'is_local',
            'personality' => 'personality',
            'vlan' => 'vlan',
            'connection_count' => 'connection_count',
            'space.data_reduction' => 'space.data_reduction',
            'space.snapshots' => 'space.snapshots',
            'space.thin_provisioning' => 'space.thin_provisioning',
            'space.total_physical' => 'space.total_physical',
            'space.total_used' => 'space.total_used',
            'space.total_provisioned' => 'space.total_provisioned',
            'space.total_reduction' => 'space.total_reduction',
            'space.unique' => 'space.unique',
            'space.virtual' => 'space.virtual',
            'iqns' => 'iqns',
            'nqns' => 'nqns',
            'wwns' => 'wwns',
            'host_group.name' => 'host_group.name',
        ]
    ]);
    echo "✓ Updated Hosts endpoint\n";
}

// Array Performance Endpoint
$arrayPerfEndpoint = RestApiEndpoint::where('name', 'Array Performance')->first();
if ($arrayPerfEndpoint) {
    $arrayPerfEndpoint->update([
        'resource_type' => 'array_performance',
        'resource_id_path' => 'id',
        'resource_name_path' => 'id',
        'metric_map' => [
            'time' => 'time',
            'id' => 'id',
            'read_bytes_per_sec' => 'read_bytes_per_sec',
            'write_bytes_per_sec' => 'write_bytes_per_sec',
            'usec_per_read_op' => 'usec_per_read_op',
            'usec_per_write_op' => 'usec_per_write_op',
            'reads_per_sec' => 'reads_per_sec',
            'writes_per_sec' => 'writes_per_sec',
            'queue_usec_per_read_op' => 'queue_usec_per_read_op',
            'queue_usec_per_write_op' => 'queue_usec_per_write_op',
            'qos_rate_limit_usec_per_read_op' => 'qos_rate_limit_usec_per_read_op',
            'qos_rate_limit_usec_per_write_op' => 'qos_rate_limit_usec_per_write_op',
            'san_usec_per_read_op' => 'san_usec_per_read_op',
            'san_usec_per_write_op' => 'san_usec_per_write_op',
            'local_queue_usec_per_op' => 'local_queue_usec_per_op',
            'usec_per_other_op' => 'usec_per_other_op',
            'others_per_sec' => 'others_per_sec',
        ]
    ]);
    echo "✓ Updated Array Performance endpoint\n";
}

// Volume Performance Endpoint
$volumePerfEndpoint = RestApiEndpoint::where('name', 'Volume Performance')->first();
if ($volumePerfEndpoint) {
    $volumePerfEndpoint->update([
        'resource_type' => 'volume_performance',
        'resource_id_path' => 'id',
        'resource_name_path' => 'id',
        'metric_map' => [
            'time' => 'time',
            'id' => 'id',
            'read_bytes_per_sec' => 'read_bytes_per_sec',
            'write_bytes_per_sec' => 'write_bytes_per_sec',
            'usec_per_read_op' => 'usec_per_read_op',
            'usec_per_write_op' => 'usec_per_write_op',
            'reads_per_sec' => 'reads_per_sec',
            'writes_per_sec' => 'writes_per_sec',
            'queue_usec_per_read_op' => 'queue_usec_per_read_op',
            'queue_usec_per_write_op' => 'queue_usec_per_write_op',
            'qos_rate_limit_usec_per_read_op' => 'qos_rate_limit_usec_per_read_op',
            'qos_rate_limit_usec_per_write_op' => 'qos_rate_limit_usec_per_write_op',
            'san_usec_per_read_op' => 'san_usec_per_read_op',
            'san_usec_per_write_op' => 'san_usec_per_write_op',
        ]
    ]);
    echo "✓ Updated Volume Performance endpoint\n";
}

// Alerts Endpoint
$alertsEndpoint = RestApiEndpoint::where('name', 'Alerts')->first();
if ($alertsEndpoint) {
    $alertsEndpoint->update([
        'resource_type' => 'alert',
        'resource_id_path' => 'id',
        'resource_name_path' => 'name',
        'metric_map' => [
            'id' => 'id',
            'name' => 'name',
            'created' => 'created',
            'updated' => 'updated',
            'closed' => 'closed',
            'state' => 'state',
            'severity' => 'severity',
            'category' => 'category',
            'code' => 'code',
            'description' => 'description',
            'component_name' => 'component_name',
            'component_type' => 'component_type',
            'issue' => 'issue',
            'summary' => 'summary',
            'flagged' => 'flagged',
            'expected' => 'expected',
            'actual' => 'actual',
        ]
    ]);
    echo "✓ Updated Alerts endpoint\n";
}

// Hardware Components Endpoint
$hardwareEndpoint = RestApiEndpoint::where('name', 'Hardware Components')->first();
if ($hardwareEndpoint) {
    $hardwareEndpoint->update([
        'resource_type' => 'hardware',
        'resource_id_path' => 'name',
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'index' => 'index',
            'type' => 'type',
            'model' => 'model',
            'status' => 'status',
            'details' => 'details',
            'serial' => 'serial',
            'speed' => 'speed',
            'slot' => 'slot',
            'temperature' => 'temperature',
            'voltage' => 'voltage',
            'identify_enabled' => 'identify_enabled',
        ]
    ]);
    echo "✓ Updated Hardware Components endpoint\n";
}

// Drives Endpoint
$drivesEndpoint = RestApiEndpoint::where('name', 'Drives')->first();
if ($drivesEndpoint) {
    $drivesEndpoint->update([
        'resource_type' => 'drive',
        'resource_id_path' => 'name',
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'type' => 'type',
            'status' => 'status',
            'capacity' => 'capacity',
            'protocol' => 'protocol',
            'details' => 'details',
        ]
    ]);
    echo "✓ Updated Drives endpoint\n";
}

echo "\n✓ All endpoints updated successfully!\n";
echo "\nNext steps:\n";
echo "1. Run polling: php lnms device:poll 1 -m rest-api -vv\n";
echo "2. Check metrics: php artisan tinker --execute=\"DB::table('device_api_metrics')->where('device_id', 1)->count()\"\n";
echo "3. View data: php artisan tinker --execute=\"DB::table('device_api_metrics')->where('device_id', 1)->limit(10)->get(['resource_type','resource_name','metric_name','value','string_value'])\"\n";
