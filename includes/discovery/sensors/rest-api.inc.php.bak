<?php

/**
 * REST API Sensor Discovery
 *
 * Discovers sensors from devices with REST API enabled
 * Uses vendor-specific API clients to fetch metrics
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

echo "\n";

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST sensor discovery\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled for device {$device['device_id']}, skipping REST sensor discovery\n");
    return;
}

d_echo("REST API Discovery: Device {$device['hostname']} ({$device['device_id']})\n");

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for device {$device['hostname']}\n");
        return;
    }

    d_echo("Using API client: " . get_class($apiClient) . "\n");

    // Check if client supports sensors
    if (!in_array('sensors', $apiClient->capabilities())) {
        d_echo("API client does not support sensor discovery\n");
        return;
    }

    // Fetch sensors from API
    d_echo("Fetching sensors from REST API...\n");
    $sensors = $apiClient->fetchSensors($deviceModel);

    if (empty($sensors)) {
        d_echo("No sensors returned from REST API\n");
        return;
    }

    d_echo("Received " . count($sensors) . " sensors from REST API\n");

    // Discover each sensor
    $discovered_count = 0;
    foreach ($sensors as $sensor_data) {
        // Required fields check
        if (!isset($sensor_data['sensor_class']) ||
            !isset($sensor_data['sensor_type']) ||
            !isset($sensor_data['sensor_descr']) ||
            !isset($sensor_data['sensor_index'])) {
            d_echo("Skipping invalid sensor data (missing required fields)\n");
            continue;
        }

        // Extract sensor data
        $class = $sensor_data['sensor_class'];
        $type = $sensor_data['sensor_type'];
        $descr = $sensor_data['sensor_descr'];
        $index = $sensor_data['sensor_index'];
        $current = $sensor_data['sensor_current'] ?? null;
        $divisor = $sensor_data['sensor_divisor'] ?? 1;
        $multiplier = $sensor_data['sensor_multiplier'] ?? 1;
        $low_limit = $sensor_data['sensor_limit_low'] ?? null;
        $low_warn_limit = $sensor_data['sensor_limit_low_warn'] ?? null;
        $warn_limit = $sensor_data['sensor_limit_warn'] ?? null;
        $high_limit = $sensor_data['sensor_limit'] ?? null;
        $entPhysicalIndex = $sensor_data['entPhysicalIndex'] ?? null;
        $group = $sensor_data['group'] ?? null;
        $rrd_type = $sensor_data['rrd_type'] ?? 'GAUGE';

        // For state sensors, we need to create state entries
        if ($class === 'state' && isset($sensor_data['states'])) {
            $states = $sensor_data['states'];

            // Create state index
            $state_name = $type . '-' . $index;
            create_sensor_to_state_index($device, $state_name, $states);

            // Discover the sensor
            $discovered = discover_sensor(
                $unused,
                $class,
                $device,
                '',  // oid - not applicable for REST API
                $index,
                $type,
                $descr,
                $divisor,
                $multiplier,
                $low_limit,
                $low_warn_limit,
                $warn_limit,
                $high_limit,
                $current,
                'rest-api',  // poller_type
                $entPhysicalIndex,
                null,  // entPhysicalIndex_measured
                null,  // user_func
                $group,
                $rrd_type
            );
        } else {
            // Discover regular sensor
            $discovered = discover_sensor(
                $unused,
                $class,
                $device,
                '',  // oid - not applicable for REST API
                $index,
                $type,
                $descr,
                $divisor,
                $multiplier,
                $low_limit,
                $low_warn_limit,
                $warn_limit,
                $high_limit,
                $current,
                'rest-api',  // poller_type
                $entPhysicalIndex,
                null,  // entPhysicalIndex_measured
                null,  // user_func
                $group,
                $rrd_type
            );
        }

        if ($discovered) {
            $discovered_count++;
            d_echo("Discovered: [$class] $descr (index: $index, current: " . ($current ?? 'N/A') . ")\n");
        }
    }

    echo "REST API Discovery: Discovered $discovered_count sensors\n";

} catch (\Throwable $e) {
    // Log error but don't fail discovery
    Log::error("REST API sensor discovery failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Discovery Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}
