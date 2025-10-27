<?php

/**
 * REST API Port Discovery
 *
 * Discovers network ports/interfaces from devices with REST API enabled
 * Uses vendor-specific API clients to fetch interface information
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST port discovery\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled for device, skipping REST port discovery\n");
    return;
}

d_echo("REST API Port Discovery: Device {$device['hostname']}\n");

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for port discovery\n");
        return;
    }

    // Check if client supports ports
    if (!in_array('ports', $apiClient->capabilities())) {
        d_echo("API client does not support port discovery\n");
        return;
    }

    // Fetch ports from API
    d_echo("Fetching ports from REST API...\n");
    $api_ports = $apiClient->fetchPorts($deviceModel);

    if (empty($api_ports)) {
        d_echo("No ports returned from REST API\n");
        return;
    }

    d_echo("Received " . count($api_ports) . " ports from REST API\n");

    // Add REST API ports to the port_stats array
    // These will be processed by the main port discovery logic
    foreach ($api_ports as $port_data) {
        // Required fields check
        if (!isset($port_data['ifIndex']) || !isset($port_data['ifName'])) {
            d_echo("Skipping invalid port data (missing ifIndex or ifName)\n");
            continue;
        }

        $ifIndex = $port_data['ifIndex'];

        // Add to port_stats array (merge with SNMP data if exists)
        if (!isset($port_stats[$ifIndex])) {
            $port_stats[$ifIndex] = [];
        }

        // Map REST API port data to LibreNMS port fields
        $port_stats[$ifIndex]['ifIndex'] = $ifIndex;
        $port_stats[$ifIndex]['ifName'] = $port_data['ifName'] ?? '';
        $port_stats[$ifIndex]['ifDescr'] = $port_data['ifDescr'] ?? $port_data['ifName'] ?? '';
        $port_stats[$ifIndex]['ifAlias'] = $port_data['ifAlias'] ?? '';
        $port_stats[$ifIndex]['ifType'] = $port_data['ifType'] ?? 'ethernetCsmacd';
        $port_stats[$ifIndex]['ifOperStatus'] = $port_data['ifOperStatus'] ?? 'unknown';
        $port_stats[$ifIndex]['ifAdminStatus'] = $port_data['ifAdminStatus'] ?? 'unknown';
        $port_stats[$ifIndex]['ifSpeed'] = $port_data['ifSpeed'] ?? 0;
        $port_stats[$ifIndex]['ifHighSpeed'] = isset($port_data['ifSpeed']) ? ($port_data['ifSpeed'] / 1000000) : 0;
        $port_stats[$ifIndex]['ifMtu'] = $port_data['ifMtu'] ?? 1500;
        $port_stats[$ifIndex]['ifPhysAddress'] = $port_data['ifPhysAddress'] ?? '';
        $port_stats[$ifIndex]['ifLastChange'] = $port_data['ifLastChange'] ?? 0;

        // Mark this port as coming from REST API
        $port_stats[$ifIndex]['_rest_api'] = true;

        d_echo("Added REST API port: ifIndex=$ifIndex, ifName=" . $port_stats[$ifIndex]['ifName'] . "\n");
    }

    echo "REST API Port Discovery: Added " . count($api_ports) . " ports\n";

} catch (\Throwable $e) {
    // Log error but don't fail discovery
    Log::error("REST API port discovery failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Port Discovery Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}
