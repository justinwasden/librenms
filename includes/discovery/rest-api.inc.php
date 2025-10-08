<?php
// includes/discovery/rest-api.inc.php

if (!defined('LibreNMS')) {
    exit;
}

// Include the main Laravel-style discovery class
use App\Discovery\RestApiDiscovery;
use App\Models\Device;

// $device is the legacy array passed by the discovery script

try {
    // Look up the Laravel Device model
    $deviceModel = Device::where('device_id', $device['device_id'])->first();

    // Check a flag (like a new column in the devices table) to decide if this module should run
    if ($deviceModel && $deviceModel->rest_api_enabled) {
        $disco = new RestApiDiscovery($deviceModel);
        $disco->discover();
        \Log::info("RestApi Discovery executed for device {$deviceModel->hostname}");
    }
} catch (Exception $e) {
    \Log::error("REST API Discovery failed for device {$device['hostname']}: {$e->getMessage()}");
}