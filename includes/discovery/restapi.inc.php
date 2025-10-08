<?php
// /opt/librenms/includes/discovery/restapi.inc.php

if (!defined('LibreNMS')) {
    exit;
}

// Test 1: Can we log anything?
\Log::info("DEBUG: restapi.inc.php started.");

// Test 2: Can we load the required classes?
use App\Discovery\RestApiDiscovery;
use App\Models\Device;

try {
    // Test 3: Can we find a device?
    $deviceModel = Device::where('device_id', $device['device_id'])->first();

    if ($deviceModel) {
        \Log::info("DEBUG: Device found: {$deviceModel->hostname}");
        // Now try to instantiate your main class
        $disco = new RestApiDiscovery($deviceModel);
        \Log::info("DEBUG: RestApiDiscovery instantiated successfully.");
        // $disco->discover(); // Keep the actual logic commented out for now
    }
} catch (\Throwable $e) {
    // Catch ALL exceptions/errors to print something
    \Log::error("DEBUG: Fatal Error in restapi.inc.php: {$e->getMessage()}");
}

// The poller will execute this if it gets here.
\Log::info("DEBUG: restapi.inc.php completed.");

?>