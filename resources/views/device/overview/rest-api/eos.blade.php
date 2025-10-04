{{--
    Arista EOS REST API Overview
    Displays switch health, MLAG status, VLAN information, and interface statistics
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

// Get MLAG information
$mlag = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'mlag')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get VLAN information
$vlans = DB::table('device_api_metrics')
    ->select([
        'resource_name', 'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "name" THEN string_value END) as vlan_name'),
        DB::raw('MAX(CASE WHEN metric_name = "ports" THEN string_value END) as ports')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'vlan')
    ->groupBy('resource_name', 'resource_id')
    ->orderBy('resource_id')
    ->get();

// Get interface information
$interfaces = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "description" THEN string_value END) as description'),
        DB::raw('MAX(CASE WHEN metric_name = "speed" THEN value END) as speed'),
        DB::raw('MAX(CASE WHEN metric_name = "in_octets" THEN value END) as in_octets'),
        DB::raw('MAX(CASE WHEN metric_name = "out_octets" THEN value END) as out_octets'),
        DB::raw('MAX(CASE WHEN metric_name = "in_errors" THEN value END) as in_errors'),
        DB::raw('MAX(CASE WHEN metric_name = "out_errors" THEN value END) as out_errors')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'interface')
    ->groupBy('resource_name')
    ->orderBy('resource_name')
    ->limit(20)
    ->get();

// Get port-channel information
$port_channels = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "protocol" THEN string_value END) as protocol'),
        DB::raw('MAX(CASE WHEN metric_name = "member_count" THEN value END) as member_count')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'port-channel')
    ->groupBy('resource_name')
    ->get();
@endphp

<!-- System Information -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i> <strong>Arista EOS System Information</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-condensed">
                            <tr><th class="col-md-4">Hostname</th><td>{{ $system_metrics['hostname']->first()->string_value ?? $device['hostname'] }}</td></tr>
                            <tr><th>Model</th><td>{{ $system_metrics['model']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>EOS Version</th><td>{{ $system_metrics['version']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>Serial Number</th><td>{{ $system_metrics['serial']->first()->string_value ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                    @if(!$mlag->isEmpty())
                    <div class="col-md-6">
                        <h4>MLAG Status</h4>
                        <table class="table table-condensed">
                            <tr><th class="col-md-4">Domain ID</th><td>{{ $mlag['domain_id']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>State</th><td><span class="label label-{{ ($mlag['state']->first()->string_value == 'active') ? 'success' : 'warning' }}">{{ strtoupper($mlag['state']->first()->string_value ?? 'UNKNOWN') }}</span></td></tr>
                            <tr><th>Peer Address</th><td>{{ $mlag['peer_address']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>Peer Link</th><td><span class="label label-{{ ($mlag['peer_link_status']->first()->string_value == 'up') ? 'success' : 'danger' }}">{{ strtoupper($mlag['peer_link_status']->first()->string_value ?? 'DOWN') }}</span></td></tr>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    @if($port_channels->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-sitemap fa-lg icon-theme"></i> <strong>Port Channels</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Port Channel</th><th>Protocol</th><th>Status</th><th class="text-right">Members</th></tr></thead>
                <tbody>
                    @foreach($port_channels as $pc)
                    <tr>
                        <td><strong>{{ $pc->resource_name }}</strong></td>
                        <td>{{ strtoupper($pc->protocol ?? 'LACP') }}</td>
                        <td><span class="label label-{{ ($pc->status == 'up') ? 'success' : 'danger' }}">{{ strtoupper($pc->status ?? 'down') }}</span></td>
                        <td class="text-right">{{ number_format($pc->member_count ?? 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($vlans->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-project-diagram fa-lg icon-theme"></i> <strong>VLANs</strong>
                <span class="badge pull-right">{{ $vlans->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>VLAN ID</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($vlans->take(10) as $vlan)
                    <tr>
                        <td><strong>{{ $vlan->resource_id ?? $vlan->resource_name }}</strong></td>
                        <td>{{ $vlan->vlan_name ?? $vlan->resource_name }}</td>
                        <td><span class="label label-{{ ($vlan->status == 'active') ? 'success' : 'default' }}">{{ strtoupper($vlan->status ?? 'ACTIVE') }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if($vlans->count() > 10)
            <div class="panel-footer text-muted small">
                Showing 10 of {{ $vlans->count() }} VLANs
            </div>
            @endif
        </div>
    </div>
    @endif
</div>

@if($interfaces->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-network-wired fa-lg icon-theme"></i> <strong>Interface Statistics</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Interface</th><th>Description</th><th>Status</th><th>Speed</th><th class="text-right">Input</th><th class="text-right">Output</th><th class="text-right">Errors</th></tr></thead>
                <tbody>
                    @foreach($interfaces as $interface)
                    @php
                        $total_errors = ($interface->in_errors ?? 0) + ($interface->out_errors ?? 0);
                    @endphp
                    <tr>
                        <td><strong>{{ $interface->resource_name }}</strong></td>
                        <td class="text-muted small">{{ substr($interface->description ?? '', 0, 30) }}</td>
                        <td><span class="label label-{{ ($interface->status == 'up' || $interface->status == 'connected') ? 'success' : 'danger' }}">{{ strtoupper($interface->status ?? 'down') }}</span></td>
                        <td>{{ (($interface->speed ?? 0) / 1000000000) > 0 ? number_format(($interface->speed ?? 0) / 1000000000) . ' Gbps' : 'N/A' }}</td>
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
