<?php

/**
 * LibreNMS
 *
 *   This file is part of LibreNMS.
 *
 * @copyright  (C) 2006 - 2012 Adam Armstrong
 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

if (is_numeric($_GET['id']) && (\App\Facades\LibrenmsConfig::get('allow_unauth_graphs') || port_permitted($_GET['id']))) {
    $port = cleanPort(get_port_by_id($_GET['id']));
    $device = device_by_id_cache($port['device_id']);
    $title = generate_device_link($device);
    $title .= ' :: Port  ' . generate_port_link($port);
    $auth = true;

    // Check if device uses SNMP or REST API
    $usesSnmp = !empty($device['snmp_version']) && !$device['snmp_disable'];

    if ($usesSnmp) {
        // SNMP-based devices: use traditional SNMP polling
        $in = snmp_get($device, 'ifHCInOctets.' . $port['ifIndex'], '-OUqnv', 'IF-MIB');
        if (empty($in)) {
            $in = snmp_get($device, 'ifInOctets.' . $port['ifIndex'], '-OUqnv', 'IF-MIB');
        }

        $out = snmp_get($device, 'ifHCOutOctets.' . $port['ifIndex'], '-OUqnv', 'IF-MIB');
        if (empty($out)) {
            $out = snmp_get($device, 'ifOutOctets.' . $port['ifIndex'], '-OUqnv', 'IF-MIB');
        }
    } else {
        // REST API-based devices: use database values (last polled statistics)
        // Realtime graphs for REST API devices show last polled data, not true realtime
        $portModel = \App\Models\Port::find($port['port_id']);
        if ($portModel) {
            // REST API devices typically provide rates, not cumulative counters
            // Convert rates to approximate cumulative values for the graph
            // Use current time to simulate counter increment
            static $baseTime = null;
            static $baseIn = 0;
            static $baseOut = 0;

            if ($baseTime === null) {
                $baseTime = microtime(true);
                $baseIn = 0;
                $baseOut = 0;
            }

            $elapsed = microtime(true) - $baseTime;

            // If we have actual counters, use them
            if ($portModel->ifInOctets !== null && $portModel->ifOutOctets !== null) {
                $in = $portModel->ifInOctets;
                $out = $portModel->ifOutOctets;
            } else {
                // Simulate counters using rates: counter = base + (rate * elapsed_time)
                $inRate = $portModel->ifInOctets_rate ?? 0;
                $outRate = $portModel->ifOutOctets_rate ?? 0;

                $in = $baseIn + ($inRate * $elapsed);
                $out = $baseOut + ($outRate * $elapsed);
            }
        } else {
            $in = 0;
            $out = 0;
        }
    }

    $time = microtime(true);

    printf("%lf|%s|%s\n", $time, $in, $out);
}
