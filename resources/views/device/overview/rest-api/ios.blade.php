{{--
    Cisco Device REST API Overview
    Displays device health, interface statistics, routing, and system information
    Works with Cisco IOS, IOS-XE, NX-OS devices
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get system information
$system_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'system')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get CPU and memory utilization
$cpu_util = $system_metrics['cpu_utilization']->first()->value ?? 0;
$mem_used = $system_metrics['memory_used']->first()->value ?? 0;
$mem_total = $system_metrics['memory_total']->first()->value ?? 0;
$mem_percent = $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 2) : 0;

// Get interface statistics
$interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name', 'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "admin_status" THEN string_value END) as admin_status'),
        DB::raw('MAX(CASE WHEN metric_name = "oper_status" THEN string_value END) as oper_status'),
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "in_octets" THEN value END) as in_octets'),
        DB::raw('MAX(CASE WHEN metric_name = "out_octets" THEN value END) as out_octets'),
        DB::raw('MAX(CASE WHEN metric_name = "in_errors" THEN value END) as in_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "out_errors" THEN value END) as out_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "description" THEN string_value END) as description')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'interface')
    ->groupBy('resource_name', 'resource_id')
    ->orderBy('resource_name')
    ->limit(20)
    ->get();

// Get routing table summary
$routing = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "route_count" THEN value END) as route_count'),
        DB::raw('MAX(CASE WHEN metric_name = "protocol" THEN string_value END) as protocol')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'routing')
    ->groupBy('resource_name')
    ->get();

// Get environmental sensors
$sensors = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "temperature" THEN value END) as temperature'),
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "threshold" THEN value END) as threshold')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'sensor')
    ->groupBy('resource_name')
    ->get();

$cpu_background = \LibreNMS\Util\Color::percentage($cpu_util, 70);
$mem_background = \LibreNMS\Util\Color::percentage($mem_percent, 80);
@endphp

<!-- System Health -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i> <strong>System Health</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-4">
                        <table class="table table-condensed">
                            <tr><th>Hostname</th><td>{{ $system_metrics['hostname']->first()->string_value ?? $device['hostname'] }}</td></tr>
                            <tr><th>Model</th><td>{{ $system_metrics['model']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>IOS Version</th><td>{{ $system_metrics['version']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>Serial Number</th><td>{{ $system_metrics['serial']->first()->string_value ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h4>CPU Utilization</h4>
                        {!! print_percentage_bar(350, 40, $cpu_util, $cpu_util . "%", 'ffffff', $cpu_background['left'], 100 - $cpu_util, 'ffffff', $cpu_background['right']) !!}
                    </div>
                    <div class="col-md-4">
                        <h4>Memory Utilization</h4>
                        {!! print_percentage_bar(350, 40, $mem_percent, Number::formatBi($mem_used) . " / " . Number::formatBi($mem_total), 'ffffff', $mem_background['left'], $mem_total - $mem_used, 'ffffff', $mem_background['right']) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($interfaces->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-network-wired fa-lg icon-theme"></i> <strong>Interface Statistics</strong>
                <span class="pull-right text-muted">Top 20 interfaces</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Interface</th><th>Description</th><th>Admin/Oper</th><th>Speed</th><th class="text-right">Input</th><th class="text-right">Output</th><th class="text-right">Errors</th></tr></thead>
                <tbody>
                    @foreach($interfaces as $interface)
                    @php
                        $total_errors = ($interface->in_errors ?? 0) + ($interface->out_errors ?? 0);
                    @endphp
                    <tr>
                        <td><strong>{{ $interface->resource_name }}</strong></td>
                        <td class="text-muted small">{{ substr($interface->description ?? '', 0, 30) }}</td>
                        <td>
                            <span class="label label-{{ ($interface->admin_status == 'up') ? 'primary' : 'default' }}">{{ strtoupper($interface->admin_status ?? 'N/A') }}</span>
                            /
                            <span class="label label-{{ ($interface->oper_status == 'up') ? 'success' : 'danger' }}">{{ strtoupper($interface->oper_status ?? 'N/A') }}</span>
                        </td>
                        <td>{{ (($interface->speed ?? 0) / 1000000) > 0 ? number_format(($interface->speed ?? 0) / 1000000) . ' Mbps' : 'N/A' }}</td>
                        <td class="text-right">{{ Number::formatBi($interface->in_octets ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($interface->out_octets ?? 0) }}</td>
                        <td class="text-right">{!! $total_errors > 0 ? '<span class="text-danger">' . number_format($total_errors) . '</span>' : '0' !!}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="row">
    @if($routing->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-route fa-lg icon-theme"></i> <strong>Routing Table Summary</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Protocol</th><th class="text-right">Route Count</th></tr></thead>
                <tbody>
                    @php $total_routes = 0; @endphp
                    @foreach($routing as $route)
                    @php $total_routes += $route->route_count ?? 0; @endphp
                    <tr>
                        <td>{{ $route->protocol ?? $route->resource_name }}</td>
                        <td class="text-right"><strong>{{ number_format($route->route_count ?? 0) }}</strong></td>
                    </tr>
                    @endforeach
                    <tr class="info">
                        <td><strong>Total Routes</strong></td>
                        <td class="text-right"><strong>{{ number_format($total_routes) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($sensors->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-thermometer-half fa-lg icon-theme"></i> <strong>Environmental Sensors</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Sensor</th><th class="text-right">Temperature</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($sensors as $sensor)
                    @php
                        $temp = $sensor->temperature ?? 0;
                        $threshold = $sensor->threshold ?? 70;
                        $temp_class = $temp > $threshold ? 'text-danger' : 'text-success';
                    @endphp
                    <tr>
                        <td>{{ $sensor->resource_name }}</td>
                        <td class="text-right"><span class="{{ $temp_class }}">{{ number_format($temp, 1) }}°C</span></td>
                        <td><span class="label label-{{ ($sensor->status == 'normal') ? 'success' : 'warning' }}">{{ strtoupper($sensor->status ?? 'UNKNOWN') }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
