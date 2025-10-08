<?php
// /opt/librenms/includes/polling/restapi.inc.php

if (!defined('LibreNMS')) {
    exit;
}

use App\Pollers\RestApiPoller;
use App\Models\Device;

// $device is the legacy array passed by the poller script

try {
    // Look up the Laravel Device model
    $deviceModel = Device::where('device_id', $device['device_id'])->first();

    // Check a flag (like rest_api_enabled, which you must migrate)
    if ($deviceModel && $deviceModel->rest_api_enabled) {
        $poller = new RestApiPoller($deviceModel);
        $poller->poll();
        \Log::info("RestApi Polling executed for device {$deviceModel->hostname}");
    }
} catch (\Exception $e) {
    // Must use \Exception since you aren't in a namespaced context
    \Log::error("REST API Polling failed for device {$device['hostname']}: {$e->getMessage()}");
}