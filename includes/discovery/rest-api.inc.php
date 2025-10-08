<?php
use App\Discovery\RestApiDiscovery;

if (!isset($device) || empty($device['device_id'])) {
    return;
}

try {
    $discovery = new RestApiDiscovery(Device::find($device['device_id']));
    $discovery->discover();
} catch (Throwable $e) {
    echo "[REST API Discovery] Error: " . $e->getMessage() . PHP_EOL;
}
