<?php

// Bootstrap Laravel to use Eloquent models and facades
if (!defined('LARAVEL_START')) {
    require base_path('bootstrap/autoload.php');
    $app = require_once base_path('bootstrap/app.php');
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

use App\Models\Device;
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
