<?php

use App\Pollers\Api as ApiPoller;
use Illuminate\Support\Facades\Log;

if (!isset($device)) {
    return;
}

$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    return;
}

$attribs = $deviceModel->getAttribs();
$enabled = isset($attribs['poll_api']) ? $attribs['poll_api'] === 'true' : ($config['poller_modules']['api'] ?? false);

if (!$enabled) {
    return;
}

Log::info('Polling REST API module for ' . $device['hostname']);

try {
    $poller = new ApiPoller($deviceModel, $config ?? []);
    $poller->poll();
} catch (Exception $e) {
    Log::error('Error polling API module for device ' . $device['hostname'] . ': ' . $e->getMessage());
}