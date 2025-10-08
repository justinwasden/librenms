<?php

/**
 * rest-api.inc.php
 * LibreNMS REST API Discovery Bridge
 */

use Illuminate\Support\Facades\Log;

if (!defined('LibreNMS')) {
    exit;
}

try {
    if (!class_exists(\App\Discovery\ApiDiscoveryBridge::class)) {
        require_once base_path('app/Discovery/ApiDiscoveryBridge.php');
    }

    $bridge = new \App\Discovery\ApiDiscoveryBridge($device);
    $bridge->run();

} catch (Throwable $e) {
    Log::error("[REST-API Discovery Bridge] {$device['hostname']}: {$e->getMessage()}");
}
