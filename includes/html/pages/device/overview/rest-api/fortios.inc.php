<?php
/**
 * Fortinet FortiGate REST API Overview
 * 
 * Displays firewall health, security policies, VPN tunnels, and threat statistics
 */

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get system information
$system_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'system')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get resource utilization
$cpu_util = $system_metrics['cpu']->first()->value ?? 0;
$mem_util = $system_metrics['memory']->first()->value ?? 0;
$session_count = $system_metrics['session_count']->first()->value ?? 0;
$session_limit = $system_metrics['session_limit']->first()->value ?? 0;
$session_percent = $session_limit > 0 ? round(($session_count / $session_limit) * 100, 2) : 0;

// Get VPN tunnels
$vpn_tunnels = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "remote_gw" THEN string_value END) as remote_gw'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_bytes" THEN value END) as tx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_bytes" THEN value END) as rx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "uptime" THEN value END) as uptime'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'vpn-tunnel')
    ->groupBy('resource_name', 'resource_id')
    ->get();

// Get security policies
$policies = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "hit_count" THEN value END) as hit_count'),
        DB::raw('MAX(CASE WHEN metric_name = "bytes" THEN value END) as bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "packets" THEN value END) as packets'),
        DB::raw('MAX(CASE WHEN metric_name = "action" THEN string_value END) as action'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'firewall-policy')
    ->groupBy('resource_name')
    ->orderBy('hit_count', 'desc')
    ->limit(10)
    ->get();

// Get threat/IPS statistics
$threats = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'metric_name',
        DB::raw('MAX(value) as count'),
        DB::raw('MAX(string_value) as severity')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'ips')
    ->groupBy('resource_name', 'metric_name')
    ->orderBy('count', 'desc')
    ->limit(10)
    ->get();

// Get interface statistics
$interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_bytes" THEN value END) as tx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_bytes" THEN value END) as rx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_packets" THEN value END) as tx_packets'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_packets" THEN value END) as rx_packets')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'interface')
    ->groupBy('resource_name')
    ->orderBy('resource_name')
    ->get();

?>

<!-- System Health -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-shield-alt fa-lg icon-theme"></i> 
                <strong>FortiGate System Health</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <table class="table table-condensed">
                            <tr>
                                <th>Hostname</th>
                                <td><?php echo htmlspecialchars($system_metrics['hostname']->first()->string_value ?? $device['hostname']); ?></td>
                            </tr>
                            <tr>
                                <th>Model</th>
                                <td><?php echo htmlspecialchars($system_metrics['model']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>FortiOS Version</th>
                                <td><?php echo htmlspecialchars($system_metrics['version']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>HA Status</th>
                                <td><?php echo htmlspecialchars($system_metrics['ha_mode']->first()->string_value ?? 'Standalone'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-3">
                        <h4>CPU Utilization</h4>
                        <?php
                        $cpu_bg = \LibreNMS\Util\Color::percentage($cpu_util, 70);
                        echo print_percentage_bar(250, 40, $cpu_util, $cpu_util . "%", 'ffffff', $cpu_bg['left'], 100 - $cpu_util, 'ffffff', $cpu_bg['right']);
                        ?>
                    </div>
                    <div class="col-md-3">
                        <h4>Memory Utilization</h4>
                        <?php
                        $mem_bg = \LibreNMS\Util\Color::percentage($mem_util, 80);
                        echo print_percentage_bar(250, 40, $mem_util, $mem_util . "%", 'ffffff', $mem_bg['left'], 100 - $mem_util, 'ffffff', $mem_bg['right']);
                        ?>
                    </div>
                    <div class="col-md-3">
                        <h4>Session Utilization</h4>
                        <?php
                        $sess_bg = \LibreNMS\Util\Color::percentage($session_percent, 80);
                        echo print_percentage_bar(250, 40, $session_percent, number_format($session_count) . " / " . number_format($session_limit), 'ffffff', $sess_bg['left'], $session_limit - $session_count, 'ffffff', $sess_bg['right']);
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- VPN Tunnels -->
<?php if ($vpn_tunnels->count() > 0): ?>
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-lock fa-lg icon-theme"></i> 
                <strong>VPN Tunnels</strong>
                <span class="badge pull-right"><?php echo $vpn_tunnels->count(); ?></span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Tunnel Name</th>
                        <th>Remote Gateway</th>
                        <th>Status</th>
                        <th class="text-right">TX</th>
                        <th class="text-right">RX</th>
                        <th class="text-right">Uptime</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vpn_tunnels as $tunnel): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($tunnel->resource_name); ?></strong></td>
                        <td><?php echo htmlspecialchars($tunnel->remote_gw ?? 'N/A'); ?></td>
                        <td>
                            <span class="label label-<?php echo ($tunnel->status == 'up') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($tunnel->status ?? 'unknown'); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo Number::formatBi($tunnel->tx_bytes ?? 0); ?></td>
                        <td class="text-right"><?php echo Number::formatBi($tunnel->rx_bytes ?? 0); ?></td>
                        <td class="text-right">
                            <?php 
                            $uptime_seconds = $tunnel->uptime ?? 0;
                            $days = floor($uptime_seconds / 86400);
                            $hours = floor(($uptime_seconds % 86400) / 3600);
                            echo $days . 'd ' . $hours . 'h';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Security Policies and Threats -->
<div class="row">
    <!-- Top Security Policies -->
    <?php if ($policies->count() > 0): ?>
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-gavel fa-lg icon-theme"></i> 
                <strong>Top Security Policies</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Policy</th>
                        <th>Action</th>
                        <th class="text-right">Hit Count</th>
                        <th class="text-right">Bytes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($policies as $policy): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($policy->resource_name); ?></td>
                        <td>
                            <span class="label label-<?php echo ($policy->action == 'accept') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($policy->action ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo number_format($policy->hit_count ?? 0); ?></td>
                        <td class="text-right"><?php echo Number::formatBi($policy->bytes ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Threat Statistics -->
    <?php if ($threats->count() > 0): ?>
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-exclamation-triangle fa-lg icon-theme"></i> 
                <strong>IPS/Threat Detection</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Threat Signature</th>
                        <th>Type</th>
                        <th class="text-right">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($threats as $threat): ?>
                    <tr>
                        <td class="small"><?php echo htmlspecialchars(substr($threat->resource_name, 0, 40)); ?></td>
                        <td>
                            <span class="label label-warning">
                                <?php echo strtoupper($threat->metric_name ?? 'IPS'); ?>
                            </span>
                        </td>
                        <td class="text-right"><strong><?php echo number_format($threat->count); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Interface Statistics -->
<?php if ($interfaces->count() > 0): ?>
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-network-wired fa-lg icon-theme"></i> 
                <strong>Network Interfaces</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Interface</th>
                        <th>Status</th>
                        <th>Speed</th>
                        <th class="text-right">TX Bytes</th>
                        <th class="text-right">RX Bytes</th>
                        <th class="text-right">TX Packets</th>
                        <th class="text-right">RX Packets</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($interfaces as $interface): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($interface->resource_name); ?></strong></td>
                        <td>
                            <span class="label label-<?php echo ($interface->status == 'up') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($interface->status ?? 'unknown'); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $speed_mbps = ($interface->speed ?? 0) / 1000000;
                            echo $speed_mbps > 0 ? number_format($speed_mbps) . ' Mbps' : 'N/A';
                            ?>
                        </td>
                        <td class="text-right"><?php echo Number::formatBi($interface->tx_bytes ?? 0); ?></td>
                        <td class="text-right"><?php echo Number::formatBi($interface->rx_bytes ?? 0); ?></td>
                        <td class="text-right"><?php echo number_format($interface->tx_packets ?? 0); ?></td>
                        <td class="text-right"><?php echo number_format($interface->rx_packets ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
