<?php

use LibreNMS\Interfaces\Discovery;

if (!defined('LibreNMS')) {
    exit;
}

try {
    $runner = new RestApiDiscovery($device);
    $runner->handle();
} catch (Exception $e) {
    \Log::error("REST API Discovery failed for device {$device['hostname']}: {$e->getMessage()}");
}
