<?php

use App\Discovery\DiscoveryRunner;

if (!defined('LibreNMS')) {
    exit;
}

try {
    $runner = new DiscoveryRunner($device);
    $runner->handle();
} catch (Exception $e) {
    \Log::error("REST API Discovery failed for device {$device['hostname']}: {$e->getMessage()}");
}
