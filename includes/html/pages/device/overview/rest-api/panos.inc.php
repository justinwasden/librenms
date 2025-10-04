<?php
/**
 * Palo Alto Networks Firewall REST API Overview
 * 
 * Displays firewall metrics, security policies, threat data, and interface statistics
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

// Get interface statistics
$interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_bytes" THEN value END) as rx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_bytes" THEN value END) as tx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_errors" THEN value END) as rx_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_errors" THEN value END) as tx_errors'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'interface')
    ->groupBy('resource_name', 'resource_id')
    ->orderBy('resource_name')
    ->get();

// Get security policy statistics
$security_policies = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "hit_count" THEN value END) as hit_count'),
        DB::raw('MAX(CASE WHEN metric_name = "bytes" THEN value END) as bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "sessions" THEN value END) as sessions'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'security-policy')
    ->groupBy('resource_name')
    ->orderBy('hit_count', 'desc')
    ->limit(10)
    ->get();

// Get threat statistics
$threats = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        'metric_name',
        DB::raw('MAX(value) as count'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'threat')
    ->groupBy('resource_name', 'metric_name')
    ->orderBy('count', 'desc')
    ->limit(10)
    ->get();

// Get session information
$sessions = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'session')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

$session_current = $sessions['active']->first()->value ?? 0;
$session_max = $sessions['max']->first()->value ?? 0;
$session_percent = $session_max > 0 ? round(($session_current / $session_max) * 100, 2) : 0;

?>

<!-- System Information -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-shield fa-lg icon-theme"></i> 
                <strong>Firewall System Information</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Hostname</th>
                                <td><?php echo htmlspecialchars($system_metrics['hostname']->first()->string_value ?? $device['hostname']); ?></td>
                            </tr>
                            <tr>
                                <th>Model</th>
                                <td><?php echo htmlspecialchars($system_metrics['model']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>PAN-OS Version</th>
                                <td><?php echo htmlspecialchars($system_metrics['sw_version']->first()->string_value ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>HA Status</th>
                                <td><?php echo htmlspecialchars($system_metrics['ha_state']->first()->string_value ?? 'Standalone'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4>Session Utilization</h4>
                        <?php
                        $background = \LibreNMS\Util\Color::percentage($session_percent, 80);
                        echo print_percentage_bar(
                            400, 
                            40, 
                            $session_percent, 
                            number_format($session_current) . " / " . number_format($session_max) . " sessions",
                            'ffffff',
                            $background['left'],
                            $session_max - $session_current,
                            'ffffff', 
                            $background['right']
                        );
                        ?>
                        <p class="text-muted small" style="margin-top: 10px;">
                            <i class="fa fa-info-circle"></i> 
                            Current: <?php echo number_format($session_current); ?> | 
                            Available: <?php echo number_format($session_max - $session_current); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Security Policies -->
<?php if ($security_policies->count() > 0): ?>
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-lock fa-lg icon-theme"></i> 
                <strong>Top Security Policies</strong>
                <span class="pull-right text-muted">By hit count</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Policy Name</th>
                        <th class="text-right">Hit Count</th>
                        <th class="text-right">Bytes</th>
                        <th class="text-right">Active Sessions</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($security_policies as $policy): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($policy->resource_name); ?></td>
                        <td class="text-right"><?php echo number_format($policy->hit_count ?? 0); ?></td>
                        <td class="text-right"><?php echo Number::formatBi($policy->bytes ?? 0); ?></td>
                        <td class="text-right"><?php echo number_format($policy->sessions ?? 0); ?></td>
                        <td class="text-muted small">
                            <?php echo \Carbon\Carbon::parse($policy->last_update)->diffForHumans(); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Interface Statistics and Threats -->
<div class="row">
    <!-- Interfaces -->
    <?php if ($interfaces->count() > 0): ?>
    <div class="col-md-6">
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
                        <th class="text-right">RX</th>
                        <th class="text-right">TX</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($interfaces as $interface): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($interface->resource_name); ?></td>
                        <td>
                            <span class="label label-<?php echo ($interface->status == 'up') ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($interface->status ?? 'unknown'); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo Number::formatBi($interface->rx_bytes ?? 0); ?></td>
                        <td class="text-right"><?php echo Number::formatBi($interface->tx_bytes ?? 0); ?></td>
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
                <i class="fa fa-bug fa-lg icon-theme"></i> 
                <strong>Threat Statistics</strong>
                <span class="pull-right text-muted">Top threats detected</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Threat Type</th>
                        <th>Category</th>
                        <th class="text-right">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($threats as $threat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($threat->resource_name); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($threat->metric_name); ?></td>
                        <td class="text-right">
                            <strong><?php echo number_format($threat->count); ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.label-success { background-color: #5cb85c; }
.label-danger { background-color: #d9534f; }
</style>
