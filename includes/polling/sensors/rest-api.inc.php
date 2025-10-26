<?php

/**
 * REST API Sensor Polling
 *
 * Polls sensors that were discovered via REST API
 * Uses vendor-specific API clients to fetch current sensor values
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

d_echo("\n");
d_echo("REST API Sensor Polling\n");

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST sensor polling\n");
    return [];
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled, skipping REST sensor polling\n");
    return [];
}

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for sensor polling\n");
        return [];
    }

    // Check if client supports sensors
    if (!in_array('sensors', $apiClient->capabilities())) {
        d_echo("API client does not support sensors\n");
        return [];
    }

    // Fetch fresh sensor data from API
    d_echo("Fetching sensors from REST API for polling...\n");
    $api_sensors = $apiClient->fetchSensors($deviceModel);

    if (empty($api_sensors)) {
        d_echo("No sensors returned from REST API\n");
        return [];
    }

    // Build index of API sensors by index for quick lookup
    $api_sensor_index = [];
    foreach ($api_sensors as $sensor_data) {
        if (isset($sensor_data['sensor_index'])) {
            $api_sensor_index[$sensor_data['sensor_index']] = $sensor_data;
        }
    }

    d_echo("Indexed " . count($api_sensor_index) . " REST API sensors\n");

    return $api_sensor_index;

} catch (\Throwable $e) {
    // Log error but don't fail polling
    Log::error("REST API sensor polling failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Sensor Polling Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
    return [];
}
