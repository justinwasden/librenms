<?php

/**
 * File: /opt/librenms/includes/polling/restapi.inc.php
 * Purpose: Integrate REST API polling into the native LibreNMS polling engine.
 */

use App\Services\RestApi\RestApiPollerService;

if (!defined('LibreNMS')) {
    exit('LibreNMS poller context required');
}

// Only run for devices that have REST API connections
$hasRestConnections = \DB::table('rest_api_connections')
    ->where('device_id', $device['device_id'])
    ->where('enabled', true)
    ->exists();

if ($hasRestConnections) {
    log_event('Polling REST API endpoints for device ' . $device['hostname'], $device['device_id'], 'system');

    try {
        RestApiPollerService::pollViaLibreNMS((object) $device);
    } catch (\Throwable $e) {
        log_event('REST API polling failed: ' . $e->getMessage(), $device['device_id'], 'system', 3);
    }
}
