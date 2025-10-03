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
        'resource_name_path' => 'name',
        'metric_map' => [
            'name' => 'name',
            'version' => 'version',
            'capacity' => 'capacity',
            'space.data_reduction' => 'space.data_reduction',
            'space.total_physical' => 'space.total_physical',
            'space.total_provisioned' => 'space.total_provisioned',
            'space.total_reduction' => 'space.total_reduction',
        ]
    ]);
    echo "✓ Updated Array Info endpoint\n";
}

// Volumes Endpoint
$volumesEndpoint = RestApiEndpoint::where('name', 'Volumes')->first();
if ($volumesEndpoint) {
    $volumesEndpoint->update([
        'resource_type' => 'volume',
        'resource_id_path' => 'id',
        'resource_name_path' => 'name',
        'metric_map' => [
            'id' => 'id',
            'name' => 'name',
            'connection_count' => 'connection_count',
            'provisioned' => 'provisioned',
            'total_physical' => 'space.total_physical',
            'total_used' => 'space.total_used',
            'data_reduction' => 'space.data_reduction',
            'total_reduction' => 'space.total_reduction',
            'serial' => 'serial',
            'volume_group.name' => 'volume_group.name',
            'pod.name' => 'pod.name',
        ]
    ]);
    echo "✓ Updated Volumes endpoint\n";
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
            'eth_address' => 'eth.address',
            'eth_gateway' => 'eth.gateway',
            'eth_mac_address' => 'eth.mac_address',
            'eth_mtu' => 'eth.mtu',
            'eth_netmask' => 'eth.netmask',
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
            'connection_count' => 'connection_count',
            'host_group.name' => 'host_group.name',
            'personality' => 'personality',
            'port_connectivity.status' => 'port_connectivity.status',
            'port_connectivity.details' => 'port_connectivity.details',
            'total_physical' => 'space.total_physical',
            'total_provisioned' => 'space.total_provisioned',
            'total_used' => 'space.total_used',
            'data_reduction' => 'space.data_reduction',
            'total_reduction' => 'space.total_reduction',
            'wwns' => 'wwns',
            'is_local' => 'is_local',
            'vlan' => 'vlan',
        ]
    ]);
    echo "✓ Updated Hosts endpoint\n";
}

echo "\n✓ All endpoints updated successfully!\n";
echo "\nNext steps:\n";
echo "1. Run diagnostic: php scripts/diagnostic_rest_api.php <device_id>\n";
echo "2. Run polling: php lnms device:poll <device_id> -m rest-api -vv\n";
echo "3. Check metrics: php artisan tinker --execute=\"DB::table('device_api_metrics')->where('device_id', <device_id>)->count()\"\n";
