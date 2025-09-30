<?php

use App\Pollers\Api as ApiPoller;
use Illuminate\Support\Facades\Log;

if (!isset($device)) {
    return;
}

$attribs = \App\Models\Device::find($device['device_id'])->getAttribs();
$enabled = isset($attribs['poll_api']) ? $attribs['poll_api'] === 'true' : ($config['poller_modules']['api'] ?? false);

if (!$enabled) {
    return;
}

Log::info('Polling REST API module');

try {
    $poller = new ApiPoller(\App\Models\Device::find($device['device_id']), $config);
    $poller->poll();
} catch (Exception $e) {
    Log::error('Error polling API module for device ' . $device['hostname'] . ': ' . $e->getMessage());
}