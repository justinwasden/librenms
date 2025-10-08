<?php
use App\Pollers\RestApiPoller;

if (!isset($device) || empty($device['device_id'])) {
    return;
}

try {
    $poller = new RestApiPoller(Device::find($device['device_id']));
    $poller->poll();
} catch (Throwable $e) {
    echo "[REST API Poller] Error: " . $e->getMessage() . PHP_EOL;
}
