<?php
/**
 * Pure Storage FlashArray REST API port discovery
 *
 * Discovers network interfaces from Pure Storage via REST API
 */

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use LibreNMS\Util\DeviceApiSettings;

// $device is an array in legacy discovery; convert to model
$deviceModel = Device::find($device['device_id'] ?? 0);
if (!$deviceModel) {
    return;
}

// Check if API is configured
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    return;
}

$client = DeviceApiClientFactory::make($deviceModel);
if (!$client || !in_array('ports', $client->capabilities())) {
    return;
}

try {
    $apiPorts = $client->fetchPorts($deviceModel);

    foreach ($apiPorts as $port) {
        $ifIndex = $port['ifIndex'] ?? 0;
        if (!$ifIndex) {
            continue;
        }

        // Add to port_stats array
        $port_stats[$ifIndex] = [
            'ifDescr' => $port['ifDescr'] ?? $port['ifName'] ?? '',
            'ifName' => $port['ifName'] ?? '',
            'ifAlias' => $port['ifAlias'] ?? '',
            'ifType' => $port['ifType'] ?? 'ethernetCsmacd',
            'ifOperStatus' => $port['ifOperStatus'] ?? 'up',
            'ifAdminStatus' => $port['ifAdminStatus'] ?? 'up',
            'ifSpeed' => $port['ifSpeed'] ?? 0,
            'ifMtu' => $port['ifMtu'] ?? 1500,
            'ifPhysAddress' => $port['ifPhysAddress'] ?? '',
        ];
    }

    \Log::info("Pure Storage API port discovery found " . count($apiPorts) . " ports", [
        'device_id' => $device['device_id'] ?? 0,
    ]);
} catch (\Throwable $e) {
    \Log::warning("Pure Storage API port discovery failed: " . $e->getMessage(), [
        'device_id' => $device['device_id'] ?? 0,
    ]);
}
