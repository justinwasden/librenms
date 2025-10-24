{{--
    Fortinet FortiGate REST API Overview
    Displays firewall health, security policies, VPN tunnels, and threat statistics
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\DB\Queries;
use LibreNMS\Util\Color;


// Get system information
$system_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'system')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

$system_model = DB::table('entPhysical')
    ->where('device_id', $device['device_id'])
    ->where('entPhysicalClass', 'chassis')
    ->first();

// --- 1. CPU Utilization ---
// LibreNMS typically aggregates CPU usage into $device->perc_cpu.
$cpu_util = $device->perc_cpu ?? 0;

// --- 2. Memory Utilization ---
// LibreNMS typically aggregates memory usage into $device->perc_mem.
$mem_util = $device->perc_mem ?? 0;

// --- 3. Session Utilization (Requires Fortinet-specific OID/Sensor) ---

// Find the Session Sensor (this is an example, the specific sensor name may vary)
$session_sensor = Queries::getRow( // <-- Now uses the imported class name
    'SELECT sensor_value, sensor_limit FROM sensors WHERE device_id = ? AND sensor_class = ? LIMIT 1',
    [$device->device_id, 'session']
);

if ($session_sensor) {
    $session_count = (int)$session_sensor['sensor_value'];
    // Use the sensor_limit as the total session capacity
    $session_limit = (int)$session_sensor['sensor_limit'] ?: 1;
    $session_percent = ($session_limit > 0) ? round(($session_count / $session_limit) * 100, 1) : 0;
} else {
    // Fallback values if the session sensor is not found
    $session_count = 0;
    $session_limit = 1;
    $session_percent = 0;
}


// Get VPN tunnels
$vpn_tunnels = DB::table('device_api_metrics')
    ->select([
        'resource_name', 'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "remote_gw" THEN string_value END) as remote_gw'),
        DB::raw('MAX(CASE WHEN metric_name = "tx_bytes" THEN value END) as tx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "rx_bytes" THEN value END) as rx_bytes'),
        DB::raw('MAX(CASE WHEN metric_name = "uptime" THEN value END) as uptime')
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
        DB::raw('MAX(CASE WHEN metric_name = "action" THEN string_value END) as action')
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
        'resource_name', 'metric_name',
        DB::raw('MAX(value) as count')
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

$cpu_bg  = Color::percentage($cpu_util, 70);
$mem_bg  = Color::percentage($mem_util, 80);
$sess_bg = Color::percentage($session_percent, 90);


@endphp

<!-- System Health -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-shield-alt fa-lg icon-theme"></i> <strong>FortiGate System Health</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <table class="table table-condensed">
                            <tr><th>Hostname</th><td>{{ isset($system_metrics['hostname']) ? ($system_metrics['hostname']->first()->string_value ?? $device['hostname']) : $device['hostname'] }}</td></tr>
                            <tr><th>Model</th><td>{{ $system_model->entPhysicalModelName ?? 'N/A' }}</td></tr>
                            <tr><th>FortiOS Version</th><td>{{ isset($system_metrics['version']) ? ($system_metrics['version']->first()->string_value ?? 'N/A') : 'N/A' }}</td></tr>
                            <tr><th>HA Status</th><td>{{ isset($system_metrics['ha_mode']) ? ($system_metrics['ha_mode']->first()->string_value ?? 'Standalone') : 'Standalone' }}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-3">
										    <h4>CPU Utilization</h4>
										    {!! print_percentage_bar(250, 40, $cpu_util, $cpu_util . "%", 'ffffff', $cpu_bg['left'], 100 - $cpu_util, 'ffffff', $cpu_bg['right']) !!}
										</div>
										<div class="col-md-3">
										    <h4>Memory Utilization</h4>
										    {!! print_percentage_bar(250, 40, $mem_util, $mem_util . "%", 'ffffff', $mem_bg['left'], 100 - $mem_util, 'ffffff', $mem_bg['right']) !!}
										</div>
										<div class="col-md-3">
										    <h4>Session Utilization</h4>
										    {{-- Session bar shows count/limit, but colors use percentage --}}
										    {!! print_percentage_bar(250, 40, $session_percent, number_format($session_count) . " / " . number_format($session_limit), 'ffffff', $sess_bg['left'], 100 - $session_percent, 'ffffff', $sess_bg['right']) !!}
										</div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($vpn_tunnels->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-lock fa-lg icon-theme"></i> <strong>VPN Tunnels</strong>
                <span class="badge pull-right">{{ $vpn_tunnels->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Tunnel Name</th><th>Remote Gateway</th><th>Status</th><th class="text-right">TX</th><th class="text-right">RX</th><th class="text-right">Uptime</th></tr></thead>
                <tbody>
                    @foreach($vpn_tunnels as $tunnel)
                    <tr>
                        <td><strong>{{ $tunnel->resource_name }}</strong></td>
                        <td>{{ $tunnel->remote_gw ?? 'N/A' }}</td>
                        <td><span class="label label-{{ ($tunnel->status == 'up') ? 'success' : 'danger' }}">{{ strtoupper($tunnel->status ?? 'unknown') }}</span></td>
                        <td class="text-right">{{ Number::formatBi($tunnel->tx_bytes ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($tunnel->rx_bytes ?? 0) }}</td>
                        <td class="text-right">{{ floor(($tunnel->uptime ?? 0) / 86400) }}d {{ floor((($tunnel->uptime ?? 0) % 86400) / 3600) }}h</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="row">
    @if($policies->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-gavel fa-lg icon-theme"></i> <strong>Top Security Policies</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Policy</th><th>Action</th><th class="text-right">Hit Count</th><th class="text-right">Bytes</th></tr></thead>
                <tbody>
                    @foreach($policies as $policy)
                    <tr>
                        <td>{{ $policy->resource_name }}</td>
                        <td><span class="label label-{{ ($policy->action == 'accept') ? 'success' : 'danger' }}">{{ strtoupper($policy->action ?? 'N/A') }}</span></td>
                        <td class="text-right">{{ number_format($policy->hit_count ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($policy->bytes ?? 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($threats->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-exclamation-triangle fa-lg icon-theme"></i> <strong>IPS/Threat Detection</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Threat Signature</th><th>Type</th><th class="text-right">Count</th></tr></thead>
                <tbody>
                    @foreach($threats as $threat)
                    <tr>
                        <td class="small">{{ substr($threat->resource_name, 0, 40) }}</td>
                        <td><span class="label label-warning">{{ strtoupper($threat->metric_name ?? 'IPS') }}</span></td>
                        <td class="text-right"><strong>{{ number_format($threat->count) }}</strong></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

@if($interfaces->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-network-wired fa-lg icon-theme"></i> <strong>Network Interfaces</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Interface</th><th>Status</th><th>Speed</th><th class="text-right">TX Bytes</th><th class="text-right">RX Bytes</th><th class="text-right">TX Packets</th><th class="text-right">RX Packets</th></tr></thead>
                <tbody>
                    @foreach($interfaces as $interface)
                    <tr>
                        <td><strong>{{ $interface->resource_name }}</strong></td>
                        <td><span class="label label-{{ ($interface->status == 'up') ? 'success' : 'danger' }}">{{ strtoupper($interface->status ?? 'unknown') }}</span></td>
                        <td>{{ (($interface->speed ?? 0) / 1000000) > 0 ? number_format(($interface->speed ?? 0) / 1000000) . ' Mbps' : 'N/A' }}</td>
                        <td class="text-right">{{ Number::formatBi($interface->tx_bytes ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($interface->rx_bytes ?? 0) }}</td>
                        <td class="text-right">{{ number_format($interface->tx_packets ?? 0) }}</td>
                        <td class="text-right">{{ number_format($interface->rx_packets ?? 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
