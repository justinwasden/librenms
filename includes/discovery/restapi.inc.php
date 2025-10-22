<?php

/**
 * File: /opt/librenms/includes/discovery/restapi.inc.php
 * Purpose: Integrate REST API discovery into the native LibreNMS discovery engine.
 */

use App\Services\RestApi\RestApiPollerService;

if (!defined('LibreNMS')) {
    exit('LibreNMS discovery context required');
}

log_event('Running REST API discovery for device ' . $device['hostname'], $device['device_id'], 'system');

try {
    RestApiPollerService::discoverRestDevices();
} catch (\Throwable $e) {
    log_event('REST API discovery failed: ' . $e->getMessage(), $device['device_id'], 'system', 3);
}
