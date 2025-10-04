<?php
/**
 * Juniper Networks REST API Overview
 * 
 * Displays Junos system health, routing engine, interfaces, and BGP peers
 */

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get routing engine information
$re_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'routing-engine')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get chassis information
$chassis_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'chassis')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

$cpu_util = $re_metrics['cpu_utilization']->first()->value ?? 0;
$mem_util = $re_metrics['memory_utilization']->first()->value ?? 0;

// Get interface statistics
$interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "admin_status" THEN string_value END) as admin_status'),
        DB::raw('MAX(CASE WHEN metric_name = "oper_status" THEN string_value END) as oper_status'),
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "input_bps" THEN value END) as input_bps'),
        DB::raw('MAX(CASE WHEN metric_name = "output_bps" THEN value END) as output_bps'),
        DB::raw('MAX(CASE WHEN metric_name = "input_errors" THEN value END) as input_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "output_errors" THEN value END) as output_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "description" THEN string_value END) as description')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'interface')
    ->groupBy('resource_name')
    ->orderBy('resource_name')
    ->limit(15)
    ->get();

// Get BGP peer information
$bgp_peers = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "state" THEN string_value END) as state'),
        DB::raw('MAX(CASE WHEN metric_name = "peer_as" THEN value END) as peer_as'),
        DB::raw('MAX(CASE WHEN metric_name = "peer_id" THEN string_value END) as peer_id'),
        DB::raw('MAX(CASE WHEN metric_name = "routes_received" THEN value END) as routes_received'),
        DB::raw('MAX(CASE WHEN metric_name = "routes_accepted" THEN value END) as routes_accepted'),
        DB::raw('MAX(CASE WHEN metric_name = "uptime" THEN value END) as uptime')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'bgp-peer')
    ->groupBy('resource_name')
    ->get();

// Get FPC (Flexible PIC Concentrator) information
$fpcs = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "state" THEN string_value END) as state'),
        DB::raw('MAX(CASE WHEN metric_name = "temperature" THEN value END) as temperature'),
        DB::raw('MAX(CASE WHEN metric_name = "memory_utilization" THEN value END) as memory_util'),
        DB::raw('MAX(CASE WHEN metric_name = "cpu_utilization" THEN value END) as cpu_util')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'fpc')
    ->groupBy('resource_name')
    ->get();

?>

<!-- System Health -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i> 
                <strong>Junos System Health</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-4">
                        <table class="table table-condensed">
                            <tr>
                                <th>Hostname</th>
                                <td><?php echo htmlspecialchars($chassis_metrics['hostname']->first()->string_value ?? $device['hostname']); ?></td>
                            </tr>
                            <tr>
                                <th>Model</th>
                                <td><?php echo htmlspecialchars($chassis_metrics['model']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Junos Version</th>
                                <td><?php echo htmlspecialchars($re_metrics['version']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Serial Number</th>
                                <td><?php echo htmlspecialchars($chassis_metrics['serial']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h4>RE CPU Utilization</h4>
                        <?php
                        $cpu_bg = \LibreNMS\Util\Color::percentage($cpu_util, 70);
                        echo print_percentage_bar(300, 40, $cpu_util, $cpu_util . "%", 'ffffff', $cpu_bg['left'], 100 - $cpu_util, 'ffffff', $cpu_bg['right']);
                        ?>
                    </div>
                    <div class="col-md-4">
                        <h4>RE Memory Utilization</h4>
                        <?php
                        $mem_bg = \LibreNMS\Util\Color::percentage($mem_util, 80);
                        echo print_percentage_bar(300, 40, $mem_util, $mem_util . "%", 'ffffff', $mem_bg['left'], 100 - $mem_util, 'ffffff', $mem_bg['right']);
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BGP Peers and FPC Status -->
<div class="row">
    <!-- BGP Peers -->
    <?php if ($bgp_peers->count() > 0): ?>
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-project-diagram fa-lg icon-theme"></i> 
                <strong>BGP Peers</strong>
                <span class="badge pull-right"><?php echo $bgp_peers->count(); ?></span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Peer IP</th>
                        <th>AS</th>
                        <th>State</th>
                        <th class="text-right">Routes</th>
                        <th>Uptime</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bgp_peers as $peer): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($peer->peer_id ?? $peer->resource_name); ?></td>
                        <td><?php echo number_format($peer->peer_as ?? 0); ?></td>
                        <td>
                            <span class="label label-<?php echo ($peer->state == 'Established') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($peer->state ?? 'down'); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo number_format($peer->routes_accepted ?? 0); ?></td>
                        <td class="text-muted small">
                            <?php 
                            $uptime_seconds = $peer->uptime ?? 0;
                            $days = floor($uptime_seconds / 86400);
                            echo $days . 'd';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- FPC Status -->
    <?php if ($fpcs->count() > 0): ?>
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-microchip fa-lg icon-theme"></i> 
                <strong>FPC Status</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>FPC</th>
                        <th>State</th>
                        <th class="text-right">Temp</th>
                        <th class="text-right">CPU</th>
                        <th class="text-right">Memory</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fpcs as $fpc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($fpc->resource_name); ?></strong></td>
                        <td>
                            <span class="label label-<?php echo ($fpc->state == 'Online') ? 'success' : 'warning'; ?>">
                                <?php echo strtoupper($fpc->state ?? 'unknown'); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo number_format($fpc->temperature ?? 0); ?>°C</td>
                        <td class="text-right"><?php echo number_format($fpc->cpu_util ?? 0); ?>%</td>
                        <td class="text-right"><?php echo number_format($fpc->memory_util ?? 0); ?>%</td>
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
                <strong>Interface Statistics</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Interface</th>
                        <th>Description</th>
                        <th>Admin/Oper</th>
                        <th>Speed</th>
                        <th class="text-right">Input</th>
                        <th class="text-right">Output</th>
                        <th class="text-right">Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($interfaces as $interface): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($interface->resource_name); ?></strong></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(substr($interface->description ?? '', 0, 30)); ?></td>
                        <td>
                            <span class="label label-<?php echo ($interface->admin_status == 'up') ? 'primary' : 'default'; ?>">
                                <?php echo strtoupper(substr($interface->admin_status ?? 'N/A', 0, 1)); ?>
                            </span>
                            /
                            <span class="label label-<?php echo ($interface->oper_status == 'up') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper(substr($interface->oper_status ?? 'N/A', 0, 1)); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $speed_mbps = ($interface->speed ?? 0) / 1000000;
                            echo $speed_mbps > 0 ? number_format($speed_mbps) . ' Mbps' : 'N/A';
                            ?>
                        </td>
                        <td class="text-right"><?php echo Number::formatBi($interface->input_bps ?? 0, 0, 2) . 'bps'; ?></td>
                        <td class="text-right"><?php echo Number::formatBi($interface->output_bps ?? 0, 0, 2) . 'bps'; ?></td>
                        <td class="text-right">
                            <?php 
                            $total_errors = ($interface->input_errors ?? 0) + ($interface->output_errors ?? 0);
                            if ($total_errors > 0) {
                                echo '<span class="text-danger">' . number_format($total_errors) . '</span>';
                            } else {
                                echo '0';
                            }
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
