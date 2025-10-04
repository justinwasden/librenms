<?php
/**
 * PureStorage FlashArray REST API Overview
 * 
 * Displays array metrics, volume performance, and host connections
 */

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get the latest array metrics
$array_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'array')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get array capacity and info
$capacity_total = $array_metrics['capacity']->first()->value ?? 0;
$capacity_used = ($array_metrics['total']->first()->value ?? 0) - ($array_metrics['space.available']->first()->value ?? 0);
$data_reduction = $array_metrics['space.data_reduction']->first()->value ?? 0;
$array_name = $array_metrics['name']->first()->string_value ?? $device['hostname'];

// Calculate capacity percentage
$capacity_percent = $capacity_total > 0 ? round(($capacity_used / $capacity_total) * 100, 2) : 0;

// Get volume metrics  
$volume_metrics = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "size" THEN value END) as size'),
        DB::raw('MAX(CASE WHEN metric_name = "provisioned" THEN value END) as provisioned'),
        DB::raw('MAX(CASE WHEN metric_name = "space.data_reduction" THEN value END) as data_reduction'),
        DB::raw('MAX(CASE WHEN metric_name = "reads_per_sec" THEN value END) as read_iops'),
        DB::raw('MAX(CASE WHEN metric_name = "writes_per_sec" THEN value END) as write_iops'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'volume')
    ->groupBy('resource_name', 'resource_id')
    ->orderBy('provisioned', 'desc')
    ->limit(10)
    ->get();

// Get host connections
$host_connections = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'resource_id', 
        DB::raw('COUNT(DISTINCT metric_name) as metric_count'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'host')
    ->groupBy('resource_name', 'resource_id')
    ->orderBy('resource_name')
    ->get();

// Get network interfaces
$network_interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "address" THEN string_value END) as address'),
        DB::raw('MAX(CASE WHEN metric_name = "services" THEN string_value END) as services'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'network-interface')
    ->groupBy('resource_name')
    ->orderBy('speed', 'desc')
    ->get();

?>

<!-- PureStorage Array Storage Overview -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <a href="device/device=<?php echo $device['device_id']; ?>/tab=health/metric=storage/">
                    <i class="fa fa-database fa-lg icon-theme" aria-hidden="true"></i> 
                    <strong>Array Storage Metrics</strong>
                </a>
            </div>
            <div class="panel-body">
                <div class="row">
                    <!-- Array Info -->
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Array Name</th>
                                <td><?php echo htmlspecialchars($array_name); ?></td>
                            </tr>
                            <tr>
                                <th>Total Capacity</th>
                                <td><?php echo Number::formatBi($capacity_total); ?></td>
                            </tr>
                            <tr>
                                <th>Used</th>
                                <td>
                                    <?php echo Number::formatBi($capacity_used); ?> 
                                    (<?php echo $capacity_percent; ?>%)
                                </td>
                            </tr>
                            <tr>
                                <th>Data Reduction Ratio</th>
                                <td><?php echo number_format($data_reduction, 2); ?>:1</td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Capacity Bar -->
                    <div class="col-md-6">
                        <h4>Capacity Utilization</h4>
                        <?php
                        $background = \LibreNMS\Util\Color::percentage($capacity_percent, null);
                        echo print_percentage_bar(
                            400, 
                            40, 
                            $capacity_percent, 
                            Number::formatBi($capacity_used) . " / " . Number::formatBi($capacity_total),
                            'ffffff',
                            $background['left'],
                            Number::formatBi($capacity_total - $capacity_used),
                            'ffffff', 
                            $background['right']
                        );
                        ?>
                        <p class="text-muted small" style="margin-top: 10px;">
                            Data Reduction: <?php echo number_format($data_reduction, 2); ?>:1 ratio saves 
                            <?php echo Number::formatBi($capacity_used * ($data_reduction - 1)); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Volume Performance Table -->
<?php if ($volume_metrics->count() > 0): ?>
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme" aria-hidden="true"></i> 
                <strong>Volume Performance</strong>
                <span class="pull-right text-muted">Top 10 by provisioned size</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Volume Name</th>
                        <th>Provisioned</th>
                        <th>Physical Used</th>
                        <th>Reduction</th>
                        <th class="text-right">Read IOPS</th>
                        <th class="text-right">Write IOPS</th>
                        <th class="text-right">Total IOPS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($volume_metrics as $volume): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($volume->resource_name); ?></td>
                        <td><?php echo Number::formatBi($volume->provisioned ?? 0); ?></td>
                        <td><?php echo Number::formatBi($volume->size ?? 0); ?></td>
                        <td><?php echo number_format($volume->data_reduction ?? 0, 2); ?>:1</td>
                        <td class="text-right"><?php echo number_format($volume->read_iops ?? 0); ?></td>
                        <td class="text-right"><?php echo number_format($volume->write_iops ?? 0); ?></td>
                        <td class="text-right">
                            <strong><?php echo number_format(($volume->read_iops ?? 0) + ($volume->write_iops ?? 0)); ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Host Connections Table -->
<?php if ($host_connections->count() > 0): ?>
<div class="row">
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme" aria-hidden="true"></i> 
                <strong>Host Connections</strong>
                <span class="badge pull-right"><?php echo $host_connections->count(); ?></span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Host Name</th>
                        <th class="text-right">Metrics</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($host_connections as $host): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($host->resource_name); ?></td>
                        <td class="text-right"><?php echo $host->metric_count; ?></td>
                        <td class="text-muted small">
                            <?php echo \Carbon\Carbon::parse($host->last_update)->diffForHumans(); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Network Interfaces -->
    <?php if ($network_interfaces->count() > 0): ?>
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-network-wired fa-lg icon-theme" aria-hidden="true"></i> 
                <strong>Network Interfaces</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Interface</th>
                        <th>Address</th>
                        <th>Speed</th>
                        <th>Services</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($network_interfaces as $interface): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($interface->resource_name); ?></td>
                        <td><?php echo htmlspecialchars($interface->address ?? 'N/A'); ?></td>
                        <td>
                            <?php 
                            $speed_gbps = ($interface->speed ?? 0) / 1000000000;
                            echo $speed_gbps > 0 ? number_format($speed_gbps) . ' Gbps' : 'N/A'; 
                            ?>
                        </td>
                        <td class="text-muted small">
                            <?php echo htmlspecialchars($interface->services ?? 'N/A'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* Custom styling for REST API metrics */
.panel-condensed .panel-heading {
    padding: 10px 15px;
}
.panel-condensed .table {
    margin-bottom: 0;
}
.badge {
    background-color: #5bc0de;
}
</style>
