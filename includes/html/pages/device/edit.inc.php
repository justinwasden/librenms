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
        echo generate_link($text, $link_array, ['section' => $type]);

        if ($vars['section'] == $type) {
            echo '</span>';
        }
        $sep = ' | ';
    }

    print_optionbar_end();

    $section = basename($vars['section']);

    // Optional debug markers (view source to see them); remove once verified
    echo "<!-- edit.inc.php: section={$section} -->";

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
        // Legacy section rendering with fallback for "device"
        $base = "includes/html/pages/device/edit/";
        if ($section === 'device') {
            // Try common filenames for Device Settings across tags
            $candidates = [
                'device.inc.php',
                'general.inc.php',
                'settings.inc.php',
            ];

            $loaded = false;
            foreach ($candidates as $f) {
                $path = $base . $f;
                echo "<!-- edit.inc.php: try {$path} exists=" . (int) is_file($path) . " -->";
                if (is_file($path)) {
                    require $path;
                    $loaded = true;
                    break;
                }
            }

            if (! $loaded) {
                // Safe warning output (no undefined helper)
                echo '<div class="alert alert-warning">Device Settings file not found. '
                   . 'Please verify legacy include files under includes/html/pages/device/edit/.</div>';
            }
        } elseif (is_file($base . $section . '.inc.php')) {
            require $base . $section . '.inc.php';
        } else {
            echo '<div class="alert alert-warning">Legacy section file not found: '
               . htmlspecialchars($base . $section . '.inc.php', ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
}

$pagetitle[] = 'Settings';