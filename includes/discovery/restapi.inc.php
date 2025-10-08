<?php
// /opt/librenms/includes/discovery/restapi.inc.php

if (!defined('LibreNMS')) {
    exit;
}

// COMMENTED OUT: use App\Discovery\RestApiDiscovery;
// COMMENTED OUT: use App\Models\Device;

\Log::info("DEBUG STEP 1: restapi.inc.php started.");

try {
    // Use full namespace
    $deviceModel = \App\Models\Device::where('device_id', $device['device_id'])->first();

    if ($deviceModel) {
        // Use full namespace
        $disco = new \App\Discovery\RestApiDiscovery($deviceModel);
        \Log::info("DEBUG STEP 3: RestApiDiscovery instantiated successfully.");
        // $disco->discover();
    }
} catch (\Throwable $e) {
    \Log::error("DEBUG: Caught exception or error: " . $e->getMessage());
}

\Log::info("DEBUG STEP 4: restapi.inc.php finished.");

?>