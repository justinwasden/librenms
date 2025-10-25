<?php

$no_refresh = true;

$link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'edit',
];

if (! Auth::user()->hasGlobalAdmin()) {
    print_error('Insufficient Privileges');
} else {
    // Build the panes list (menu tabs)
    $panes = [];
    $panes['device'] = 'Device Settings';
    $panes['snmp'] = 'SNMP';

    // Insert the new Device API tab right after SNMP
    $panes['api'] = 'Device API';

    if (! $device['snmp_disable']) {
        $panes['ports'] = 'Port Settings';
    }

    if (dbFetchCell('SELECT COUNT(*) FROM `bgpPeers` WHERE `device_id` = ? LIMIT 1', [$device['device_id']]) > 0) {
        $panes['routing'] = 'Routing';
    }

    if (count(\App\Facades\LibrenmsConfig::get("os.{$device['os']}.icons", []))) {
        $panes['icon'] = 'Icon';
    }

    if (! $device['snmp_disable']) {
        $panes['apps'] = 'Applications';
    }

    $panes['alert-rules'] = 'Alert Rules';

    if (! $device['snmp_disable']) {
        $panes['modules'] = 'Modules';
    }

    if (\App\Facades\LibrenmsConfig::get('show_services')) {
        $panes['services'] = 'Services';
    }

    $panes['ipmi'] = 'IPMI';

    if (dbFetchCell("SELECT COUNT(*) FROM `sensors` WHERE `device_id` = ? AND `sensor_deleted`='0' LIMIT 1", [$device['device_id']]) > 0) {
        $panes['health'] = 'Health';
    }

    if (dbFetchCell("SELECT COUNT(*) FROM `wireless_sensors` WHERE `device_id` = ? AND `sensor_deleted`='0' LIMIT 1", [$device['device_id']]) > 0) {
        $panes['wireless-sensors'] = 'Wireless Sensors';
    }

    if (! $device['snmp_disable']) {
        $panes['storage'] = 'Storage';
        $panes['processors'] = 'Processors';
        $panes['mempools'] = 'Memory';
    }

    $panes['misc'] = 'Misc';
    $panes['component'] = 'Components';
    $panes['customoid'] = 'Custom OID';

    print_optionbar_start();

    $sep = '';
    foreach ($panes as $type => $text) {
        if (! isset($vars['section'])) {
            $vars['section'] = $type;
        }
        echo $sep;

        if ($vars['section'] == $type) {
            echo "<span class='pagemenu-selected'>";
        }

        // Device Settings tab goes to Blade edit route
        if ($type == 'device') {
            echo '<a href="' . route('device.edit', [$device['device_id']]) . "\">$text</a>";
        }
        // New Device API tab: also goes to Blade edit route with section=api
        elseif ($type == 'api') {
            echo '<a href="' . route('device.edit', [$device['device_id']]) . '?section=api' . "\">$text</a>";
        }
        // All other tabs use legacy include routing
        else {
            echo generate_link($text, $link_array, ['section' => $type]);
        }

        if ($vars['section'] == $type) {
            echo '</span>';
        }
        $sep = ' | ';
    }

    print_optionbar_end();

    $section = basename($vars['section']);

    // If section is 'device' or 'api', render via Blade (handled by DeviceController@edit)
    if ($section === 'device' || $section === 'api') {
        // Nothing to include here; Blade view will render these sections.
    } elseif (is_file("includes/html/pages/device/edit/$section.inc.php")) {
        require "includes/html/pages/device/edit/$section.inc.php";
    }
}

$pagetitle[] = 'Settings';