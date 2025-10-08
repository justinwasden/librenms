<?php
// /opt/librenms/includes/discovery/restapi.inc.php

if (!defined('LibreNMS')) {
    exit;
}

// STEP 1: Test simple execution and logging
\Log::info("DEBUG STEP 1: restapi.inc.php started.");

// STEP 2: Test class loading - Fatal error likely happens here
use App\Discovery\RestApiDiscovery;
use App\Models\Device;

\Log::info("DEBUG STEP 2: Classes were 'use'd."); // This line might be reached

try {
    // This is often where fatal errors occur if the file isn't autoloaded:
    // $deviceModel = Device::where('device_id', $device['device_id'])->first();

    // Test 3: Can we successfully instantiate a class?
    $test_discovery = new RestApiDiscovery((object)['id' => 3, 'hostname' => 'test']);
    \Log::info("DEBUG STEP 3: RestApiDiscovery instantiated.");

} catch (\Throwable $e) {
    // Use \Throwable to catch any error, even fatal ones
    \Log::error("DEBUG: Caught exception or error: " . $e->getMessage());
}

// If execution reaches here, the module should unload cleanly.
\Log::info("DEBUG STEP 4: restapi.inc.php finished.");

?>