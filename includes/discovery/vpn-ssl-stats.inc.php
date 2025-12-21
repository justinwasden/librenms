<?php

use App\Models\Device;

echo "\nVPN SSL Stats: ";

/** @var Device $device */

// check if the OS has a discoverVpnSslStats method
if (!method_exists($os, 'discoverVpnSslStats')) {
    echo "Unsupported\n";
    return;
}

$stats = $os->discoverVpnSslStats();

if (empty($stats)) {
    echo "No stats found\n";
    return;
}

foreach ($stats as $sensor) {
    discover_sensor($sensor, 'traffic', $device, null, $sensor['sensor_index'], 'fortinet-vpn-ssl-stats', $sensor['sensor_descr'], 1, 1, null, null, null, null, $sensor['sensor_current']);
}

echo "\n";


