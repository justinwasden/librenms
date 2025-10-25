<?php

$no_refresh = true;

$link_array = [
    'page'   => 'device',
    'device' => $device['device_id'],
    'tab'    => 'edit',
];

if (! Auth::user()->hasGlobalAdmin()) {
    print_error('Insufficient Privileges');
} else {
    // Detect the actual legacy file that renders "Device Settings"
    $base = "includes/html/pages/device/edit/";
    $device_settings_candidates = [
        'device.inc.php',     // classic
        'general.inc.php',    // some tags
        'settings.inc.php',   // alternative naming
    ];
    $deviceSectionFile = null;
    foreach ($device_settings_candidates as $f) {
        if (is_file($base . $f)) {
            $deviceSectionFile = $f;
            break;
        }
    }
    // Fallback: if none of the candidates exist, we will still show the tab, but warn when loaded.

    // Build the panes list (menu tabs)
    $panes = [];
    $panes['device'] = 'Device Settings';
    $panes['snmp']   = 'SNMP';
    $panes['api']    = 'Device API'; // new tab

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
        $panes['storage']    = 'Storage';
        $panes['processors'] = 'Processors';
        $panes['mempools']   = 'Memory';
    }
    $panes['misc']      = 'Misc';
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

        // All tabs use legacy navigation
        // For the "device" tab, force section name to the detected file (if we found one)
        if ($type === 'device' && $deviceSectionFile) {
            // use the base name without .inc.php as the section
            $sectionName = basename($deviceSectionFile, '.inc.php');
            echo generate_link($text, $link_array, ['section' => $sectionName]);
        } else {
            echo generate_link($text, $link_array, ['section' => $type]);
        }

        if ($vars['section'] == $type) {
            echo '</span>';
        }
        $sep = ' | ';
    }

    print_optionbar_end();

    // Resolve selected section
    $section = basename($vars['section']);

    // Debug markers (view source to see); remove when done
    echo "<!-- edit.inc.php: section={$section} deviceSectionFile=" . htmlspecialchars((string)$deviceSectionFile) . " -->";

    if ($section === 'api') {
        // Render Device API form inline within the legacy wrapper
        $deviceModel = \App\Models\Device::findOrFail($device['device_id']);

        echo '<form method="POST" action="' . route('device.edit.update', ['device' => $device['device_id']]) . '">';
        echo csrf_field();
        echo method_field('PUT');

        echo view('device.partials.device_api', ['device' => $deviceModel])->render();

        echo '<div class="mt-3">';
        echo '  <button type="submit" class="btn btn-primary">Save Changes</button>';
        echo '  <a href="' . url("device/{$device['device_id']}") . '" class="btn btn-secondary">Cancel</a>';
        echo '</div>';

        echo '</form>';
    } else {
        // Legacy section rendering
        // If "device" is selected, map to the detected file's section name
        if ($section === 'device' && $deviceSectionFile) {
            $section = basename($deviceSectionFile, '.inc.php');
        }

        $path = $base . $section . '.inc.php';
        echo "<!-- edit.inc.php: load path={$path} exists=" . (int) is_file($path) . " -->";

        if (is_file($path)) {
            require $path;
        } else {
            // Final fallback: inform about missing file and list directory contents
            echo '<div class="alert alert-warning">Device Settings content file not found at '
               . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '.<br>'
               . 'Please check available files under ' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '.</div>';
        }
    }
}

$pagetitle[] = 'Settings';