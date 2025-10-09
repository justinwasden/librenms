{{--
    PureStorage FlashArray REST API Overview
    Displays array metrics, volume performance, and host connections
    Updated to use new storage array tables
--}}

@php
use App\Models\StorageArray;
use App\Models\StorageController;
use App\Models\StorageArrayHost;
use App\Models\StorageArrayVolume;
use LibreNMS\Util\Number;

$device_id = $device['device_id'];

// Get array information
$array = StorageArray::where('device_id', $device_id)->first();

// Get controllers
$controllers = StorageController::where('device_id', $device_id)->get();

// Get hosts
$hosts = StorageArrayHost::where('device_id', $device_id)->orderBy('name')->get();

// Get top 10 volumes by provisioned size
$volumes = StorageArrayVolume::where('device_id', $device_id)
    ->orderBy('total_provisioned', 'desc')
    ->limit(10)
    ->get();

// Calculate totals if array data exists
$capacity_total = $array->total_capacity ?? 0;
$capacity_used = $array->total_used ?? 0;
$capacity_percent = $capacity_total > 0 ? round(($capacity_used / $capacity_total) * 100, 2) : 0;
$data_reduction = $array->data_reduction ?? 0;
$total_reduction = $array->total_reduction ?? 0;
$array_name = $array->name ?? $device['hostname'];

$background = \LibreNMS\Util\Color::percentage($capacity_percent, null);

// Check if we have any data
$has_data = $array || $controllers->count() > 0 || $hosts->count() > 0 || $volumes->count() > 0;
@endphp

@if(!$has_data)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i> 
            <strong>No REST API data collected yet.</strong> 
            Data will appear after the next polling cycle. 
            <br><small>Please ensure:</small>
            <ul class="small" style="margin-top: 5px; margin-bottom: 0;">
                <li>REST API connection is properly configured</li>
                <li>Endpoint resource types are set correctly (array, controller, host, volume)</li>
                <li>Device is reachable and polling has completed</li>
            </ul>
        </div>
    </div>
</div>
@else

<!-- Array Storage Overview -->
@if($array)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i> 
                <strong>Array Storage Metrics</strong>
                @if($array->last_polled)
                <span class="pull-right text-muted small">
                    Last updated: {{ $array->last_polled->diffForHumans() }}
                </span>
                @endif
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Array Name</th>
                                <td>{{ $array_name }}</td>
                            </tr>
                            @if($array->model)
                            <tr>
                                <th>Model</th>
                                <td>{{ $array->model }}</td>
                            </tr>
                            @endif
                            @if($array->version)
                            <tr>
                                <th>Version</th>
                                <td>{{ $array->version }}</td>
                            </tr>
                            @endif
                            <tr>
                                <th>Total Capacity</th>
                                <td>{{ Number::formatBi($capacity_total) }}</td>
                            </tr>
                            <tr>
                                <th>Used</th>
                                <td>{{ Number::formatBi($capacity_used) }} ({{ $capacity_percent }}%)</td>
                            </tr>
                            <tr>
                                <th>Available</th>
                                <td>{{ Number::formatBi($capacity_total - $capacity_used) }}</td>
                            </tr>
                            @if($array->total_provisioned > 0)
                            <tr>
                                <th>Total Provisioned</th>
                                <td>{{ Number::formatBi($array->total_provisioned) }}</td>
                            </tr>
                            @endif
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            @if($data_reduction > 0)
                            <tr>
                                <th class="col-md-6">Data Reduction</th>
                                <td><span class="label label-success">{{ number_format($data_reduction, 2) }}:1</span></td>
                            </tr>
                            @endif
                            @if($total_reduction > 0)
                            <tr>
                                <th>Total Reduction</th>
                                <td><span class="label label-success">{{ number_format($total_reduction, 2) }}:1</span></td>
                            </tr>
                            @endif
                            @if($array->snapshots > 0)
                            <tr>
                                <th>Snapshots</th>
                                <td>{{ Number::formatBi($array->snapshots) }}</td>
                            </tr>
                            @endif
                            @if($array->system > 0)
                            <tr>
                                <th>System Overhead</th>
                                <td>{{ Number::formatBi($array->system) }}</td>
                            </tr>
                            @endif
                            @if($array->status)
                            <tr>
                                <th>Status</th>
                                <td>
                                    @if($array->status === 'ok')
                                    <span class="label label-success">{{ strtoupper($array->status) }}</span>
                                    @else
                                    <span class="label label-warning">{{ strtoupper($array->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endif
                        </table>
                        
                        <h5><strong>Capacity Utilization</strong></h5>
                        {!! print_percentage_bar(400, 40, $capacity_percent, Number::formatBi($capacity_used) . " / " . Number::formatBi($capacity_total), 'ffffff', $background['left'], Number::formatBi($capacity_total - $capacity_used), 'ffffff', $background['right']) !!}
                        
                        @if($data_reduction > 1)
                        <p class="text-muted small" style="margin-top: 10px;">
                            <i class="fa fa-compress"></i> Data Reduction: {{ number_format($data_reduction, 2) }}:1 saves {{ Number::formatBi($capacity_used * ($data_reduction - 1)) }}
                        </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Controllers -->
@if($controllers->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-microchip fa-lg icon-theme"></i> 
                <strong>Controllers</strong>
                <span class="badge pull-right">{{ $controllers->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Model</th>
                        <th>Version</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Last Polled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($controllers as $controller)
                    <tr>
                        <td><strong>{{ $controller->name }}</strong></td>
                        <td>{{ $controller->model ?? 'N/A' }}</td>
                        <td>{{ $controller->version ?? 'N/A' }}</td>
                        <td>
                            @if($controller->mode === 'primary')
                            <span class="label label-primary">{{ strtoupper($controller->mode) }}</span>
                            @else
                            <span class="label label-default">{{ strtoupper($controller->mode ?? 'UNKNOWN') }}</span>
                            @endif
                        </td>
                        <td>
                            @if($controller->status === 'ok')
                            <span class="label label-success">OK</span>
                            @elseif($controller->status === 'degraded')
                            <span class="label label-warning">DEGRADED</span>
                            @else
                            <span class="label label-danger">{{ strtoupper($controller->status ?? 'UNKNOWN') }}</span>
                            @endif
                        </td>
                        <td class="text-muted small">
                            {{ $controller->last_polled ? $controller->last_polled->diffForHumans() : 'Never' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<!-- Volumes -->
@if($volumes->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme"></i> 
                <strong>Volumes</strong>
                <span class="pull-right text-muted">Top 10 by provisioned size</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Volume Name</th>
                        <th>Provisioned</th>
                        <th>Used</th>
                        <th>Physical</th>
                        <th>Data Reduction</th>
                        <th>Volume Group</th>
                        <th>Hosts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($volumes as $volume)
                    @php
                    $vol_used_percent = $volume->total_provisioned > 0 ? round(($volume->used_provisioned / $volume->total_provisioned) * 100, 1) : 0;
                    @endphp
                    <tr>
                        <td><strong>{{ $volume->name }}</strong></td>
                        <td>{{ Number::formatBi($volume->total_provisioned) }}</td>
                        <td>{{ Number::formatBi($volume->used_provisioned) }} <small class="text-muted">({{ $vol_used_percent }}%)</small></td>
                        <td>{{ Number::formatBi($volume->total_physical) }}</td>
                        <td>
                            @if($volume->data_reduction > 0)
                            <span class="label label-success">{{ number_format($volume->data_reduction, 2) }}:1</span>
                            @else
                            N/A
                            @endif
                        </td>
                        <td class="text-muted small">{{ $volume->volume_group ?? '-' }}</td>
                        <td class="text-center">
                            @if($volume->host_count > 0)
                            <span class="badge badge-info">{{ $volume->host_count }}</span>
                            @else
                            <span class="text-muted">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<!-- Hosts -->
@if($hosts->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i> 
                <strong>Host Connections</strong>
                <span class="badge pull-right">{{ $hosts->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Host Name</th>
                        <th>Connectivity</th>
                        <th>Connections</th>
                        <th>Volumes</th>
                        <th>Host Group</th>
                        <th>IQNs/WWNs</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hosts as $host)
                    <tr>
                        <td><strong>{{ $host->name }}</strong></td>
                        <td>
                            @if($host->port_connectivity_status === 'connected')
                            <span class="label label-success">CONNECTED</span>
                            @elseif($host->port_connectivity_status === 'partially_connected')
                            <span class="label label-warning">PARTIAL</span>
                            @else
                            <span class="label label-danger">DISCONNECTED</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $host->connection_count }}</td>
                        <td class="text-center">
                            @if($host->volume_count > 0)
                            <span class="badge badge-primary">{{ $host->volume_count }}</span>
                            @else
                            -
                            @endif
                        </td>
                        <td class="text-muted small">{{ $host->host_group ?? '-' }}</td>
                        <td class="text-muted small">
                            @if($host->iqns && count($host->iqns) > 0)
                            {{ count($host->iqns) }} IQN{{ count($host->iqns) > 1 ? 's' : '' }}
                            @elseif($host->wwns && count($host->wwns) > 0)
                            {{ count($host->wwns) }} WWN{{ count($host->wwns) > 1 ? 's' : '' }}
                            @else
                            -
                            @endif
                        </td>
                        <td class="text-muted small">
                            {{ $host->last_seen ? $host->last_seen->diffForHumans() : 'Never' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@endif
