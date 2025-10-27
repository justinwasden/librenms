<?php

/**
 * REST API Port Polling
 *
 * Polls ports that were discovered via REST API or supplements SNMP port data
 * Uses vendor-specific API clients to fetch current port statistics
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

d_echo("\n");
d_echo("REST API Port Polling\n");

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST port polling\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled, skipping REST port polling\n");
    return;
}

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for port polling\n");
        return;
    }

    // Check if client supports ports
    if (!in_array('ports', $apiClient->capabilities())) {
        d_echo("API client does not support ports\n");
        return;
    }

    // Fetch ports from API
    d_echo("Fetching ports from REST API for polling...\n");
    $api_ports = $apiClient->fetchPorts($deviceModel);

    if (empty($api_ports)) {
        d_echo("No ports returned from REST API\n");
        return;
    }

    d_echo("Received " . count($api_ports) . " ports from REST API\n");

    // Build index of API ports by ifIndex for quick lookup
    foreach ($api_ports as $port_data) {
        $ifIndex = $port_data['ifIndex'] ?? null;
        if ($ifIndex === null) {
            continue;
        }

        // Check if this port exists in our database
        $db_port = dbFetchRow('SELECT * FROM `ports` WHERE `device_id` = ? AND `ifIndex` = ?', [$device['device_id'], $ifIndex]);

        if (!$db_port) {
            d_echo("Port ifIndex=$ifIndex not found in database, skipping\n");
            continue;
        }

        // Update port operational data from API
        $update_data = [];

        if (isset($port_data['ifOperStatus'])) {
            $update_data['ifOperStatus'] = $port_data['ifOperStatus'];
        }

        if (isset($port_data['ifAdminStatus'])) {
            $update_data['ifAdminStatus'] = $port_data['ifAdminStatus'];
        }

        if (isset($port_data['ifSpeed'])) {
            $update_data['ifSpeed'] = $port_data['ifSpeed'];
            $update_data['ifHighSpeed'] = $port_data['ifSpeed'] / 1000000; // Convert to Mbps
        }

        if (!empty($update_data)) {
            dbUpdate($update_data, 'ports', '`port_id` = ?', [$db_port['port_id']]);
            d_echo("Updated port ifIndex=$ifIndex with REST API data\n");
        }

        // Store port statistics if provided
        // Note: Some APIs provide rates, some provide counters
        // We'll assume the API provides counters unless specified otherwise
        $stats = [];

        foreach (['ifInOctets', 'ifOutOctets', 'ifInUcastPkts', 'ifOutUcastPkts',
                  'ifInErrors', 'ifOutErrors', 'ifInDiscards', 'ifOutDiscards'] as $stat) {
            if (isset($port_data[$stat])) {
                $stats[$stat] = $port_data[$stat];
            }
        }

        if (!empty($stats)) {
            // Update port statistics in the ports table
            dbUpdate($stats, 'ports', '`port_id` = ?', [$db_port['port_id']]);
            d_echo("Updated port stats for ifIndex=$ifIndex\n");
        }
    }

    echo "REST API Port Polling: Polled " . count($api_ports) . " ports\n";

} catch (\Throwable $e) {
    // Log error but don't fail polling
    Log::error("REST API port polling failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Port Polling Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}
