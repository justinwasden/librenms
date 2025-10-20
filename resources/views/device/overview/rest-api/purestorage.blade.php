{{--
    PureStorage FlashArray REST API Overview
    Displays array capacity, performance metrics, and hardware status
    Uses the device_api_metrics table populated by the REST API poller
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Color;

$device_id = $device['device_id'];

// Query device_api_metrics for Pure Storage array data
$array_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device_id)
    ->where('resource_type', 'arrays')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Extract key capacity metrics
$capacity = $array_metrics->get('capacity')?->first()?->value ?? 0;
$total_physical = $array_metrics->get('total_physical')?->first()?->value ?? 0;
$total_used = $array_metrics->get('total_used')?->first()?->value ?? 0;
$total_provisioned = $array_metrics->get('total_provisioned')?->first()?->value ?? 0;
$data_reduction = $array_metrics->get('data_reduction')?->first()?->value ?? 1;
$total_reduction = $array_metrics->get('total_reduction')?->first()?->value ?? 1;
$unique = $array_metrics->get('unique')?->first()?->value ?? 0;
$shared = $array_metrics->get('shared')?->first()?->value ?? 0;
$snapshots = $array_metrics->get('snapshots')?->first()?->value ?? 0;

// Extract array info metrics
$array_name = $array_metrics->get('name')?->first()?->string_value ?? $device['hostname'];
$array_version = $array_metrics->get('version')?->first()?->string_value ?? 'Unknown';
$array_os = $array_metrics->get('os')?->first()?->string_value ?? 'Purity';

// Calculate utilization percentage
$capacity_percent = $capacity > 0 ? round(($total_used / $capacity) * 100, 2) : 0;

// Get performance metrics
$perf_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device_id)
    ->where('resource_type', 'arrays')
    ->where('metric_name', 'like', 'read_%')
    ->orWhere('metric_name', 'like', 'write_%')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

$read_iops = $perf_metrics->get('reads_per_sec')?->first()?->value ?? 0;
$write_iops = $perf_metrics->get('writes_per_sec')?->first()?->value ?? 0;
$read_latency = $perf_metrics->get('usec_per_read_op')?->first()?->value ?? 0;
$write_latency = $perf_metrics->get('usec_per_write_op')?->first()?->value ?? 0;

// Get ONLY controller entities (CT0, CT1) - filter out all other hardware
$controllers = DB::table('device_api_metrics')
    ->where('device_id', $device_id)
    ->where('resource_type', 'controllers')
    ->orderBy('resource_name')
    ->get()
    ->groupBy('resource_name');

$has_data = $array_metrics->count() > 0;
@endphp

@if(!$has_data)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>No Pure Storage data available</strong>
            <br>Data will appear after the next polling cycle. Ensure the REST API poller is configured with Pure Storage credentials.
        </div>
    </div>
</div>
@else

<!-- Array Storage Overview Panel -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i>
                <strong>Array Storage Metrics</strong>
                <span class="pull-right text-muted small">{{ $array_name }} | Purity {{ $array_version }}</span>
            </div>
            <div class="panel-body">
                <div class="row">
                    <!-- Array Info Table -->
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Array Name</th>
                                <td><strong>{{ $array_name }}</strong></td>
                            </tr>
                            <tr>
                                <th>Purity Version</th>
                                <td>{{ $array_version }}</td>
                            </tr>
                            <tr>
                                <th>Operating System</th>
                                <td>{{ $array_os }}</td>
                            </tr>
                            <tr>
                                <th colspan="2"><hr style="margin: 5px 0;"></th>
                            </tr>
                            <tr>
                                <th>Total Capacity</th>
                                <td>{{ Number::formatBi($capacity) }}</td>
                            </tr>
                            <tr>
                                <th>Provisioned</th>
                                <td>{{ Number::formatBi($total_provisioned) }}</td>
                            </tr>
                            <tr>
                                <th>Physical Used</th>
                                <td>{{ Number::formatBi($total_used) }}</td>
                            </tr>
                            <tr>
                                <th>Available</th>
                                <td>{{ Number::formatBi($capacity - $total_used) }}</td>
                            </tr>
                        </table>
                    </div>

                    <!-- Capacity Utilization Chart -->
                    <div class="col-md-6">
                        <h5><strong>Capacity Utilization</strong></h5>
                        @php
                            $background = Color::percentage($capacity_percent, null);
                        @endphp
                        {!! print_percentage_bar(
                            400,
                            40,
                            $capacity_percent,
                            Number::formatBi($total_used) . ' / ' . Number::formatBi($capacity),
                            'ffffff',
                            $background['left'],
                            Number::formatBi($capacity - $total_used),
                            'ffffff',
                            $background['right']
                        ) !!}

                        <!-- Reduction Metrics -->
                        <div style="margin-top: 15px;">
                            <table class="table table-condensed table-striped">
                                <tr>
                                    <th>Data Reduction</th>
                                    <td><span class="label label-success">{{ number_format($data_reduction, 2) }}:1</span></td>
                                </tr>
                                <tr>
                                    <th>Total Reduction</th>
                                    <td><span class="label label-info">{{ number_format($total_reduction, 2) }}:1</span></td>
                                </tr>
                                <tr>
                                    <th>Effective Savings</th>
                                    <td>
                                        @php
                                            $saved = $total_used * ($total_reduction - 1);
                                        @endphp
                                        {{ Number::formatBi($saved) }}
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Space Breakdown -->
                <hr style="margin: 15px 0;">
                <h5><strong>Space Breakdown</strong></h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="panel panel-info" style="margin-bottom: 10px;">
                            <div class="panel-heading" style="padding: 8px 15px; font-size: 12px;">
                                <strong>Unique Data</strong>
                            </div>
                            <div class="panel-body" style="padding: 10px 15px; text-align: center;">
                                <h4 style="margin: 0;">{{ Number::formatBi($unique) }}</h4>
                                <small class="text-muted">{{ $total_used > 0 ? round(($unique/$total_used)*100, 1) : 0 }}% of used</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-warning" style="margin-bottom: 10px;">
                            <div class="panel-heading" style="padding: 8px 15px; font-size: 12px;">
                                <strong>Shared Data</strong>
                            </div>
                            <div class="panel-body" style="padding: 10px 15px; text-align: center;">
                                <h4 style="margin: 0;">{{ Number::formatBi($shared) }}</h4>
                                <small class="text-muted">{{ $total_used > 0 ? round(($shared/$total_used)*100, 1) : 0 }}% of used</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-danger" style="margin-bottom: 10px;">
                            <div class="panel-heading" style="padding: 8px 15px; font-size: 12px;">
                                <strong>Snapshots</strong>
                            </div>
                            <div class="panel-body" style="padding: 10px 15px; text-align: center;">
                                <h4 style="margin: 0;">{{ Number::formatBi($snapshots) }}</h4>
                                <small class="text-muted">{{ $total_used > 0 ? round(($snapshots/$total_used)*100, 1) : 0 }}% of used</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-success" style="margin-bottom: 10px;">
                            <div class="panel-heading" style="padding: 8px 15px; font-size: 12px;">
                                <strong>Available</strong>
                            </div>
                            <div class="panel-body" style="padding: 10px 15px; text-align: center;">
                                <h4 style="margin: 0;">{{ Number::formatBi($capacity - $total_used) }}</h4>
                                <small class="text-muted">{{ $capacity > 0 ? round((($capacity - $total_used)/$capacity)*100, 1) : 0 }}% free</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Performance Metrics Panel -->
@if($read_iops > 0 || $write_iops > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-tachometer fa-lg icon-theme"></i>
                <strong>Performance Metrics</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <h5>Read IOPS</h5>
                        <h3 style="color: #5cb85c; margin: 10px 0;">{{ number_format($read_iops) }}</h3>
                        @if($read_latency > 0)
                        <small class="text-muted">Latency: {{ number_format($read_latency/1000, 2) }}ms</small>
                        @endif
                    </div>
                    <div class="col-md-3 text-center">
                        <h5>Write IOPS</h5>
                        <h3 style="color: #0275d8; margin: 10px 0;">{{ number_format($write_iops) }}</h3>
                        @if($write_latency > 0)
                        <small class="text-muted">Latency: {{ number_format($write_latency/1000, 2) }}ms</small>
                        @endif
                    </div>
                    <div class="col-md-3 text-center">
                        <h5>Total IOPS</h5>
                        <h3 style="color: #5f27cd; margin: 10px 0;">{{ number_format($read_iops + $write_iops) }}</h3>
                    </div>
                    <div class="col-md-3 text-center">
                        <h5>Read/Write Ratio</h5>
                        <h3 style="color: #f39c12; margin: 10px 0;">
                            @php
                                $total = $read_iops + $write_iops;
                                $ratio = $total > 0 ? round(($read_iops / $total) * 100) : 0;
                            @endphp
                            {{ $ratio }}% / {{ 100 - $ratio }}%
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Controllers Panel -->
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
                        <th>Controller Name</th>
                        <th>Model</th>
                        <th>Version</th>
                        <th>Serial</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($controllers as $controller_name => $metrics)
                        @php
                            // Extract metrics from grouped data
                            $model = $metrics->where('metric_name', 'model')->first()?->string_value ?? 'N/A';
                            $version = $metrics->where('metric_name', 'version')->first()?->string_value ?? 'N/A';
                            $serial = $metrics->where('metric_name', 'serial')->first()?->string_value ?? 'N/A';
                            $status = $metrics->where('metric_name', 'status')->first()?->string_value ?? 'UNKNOWN';
                        @endphp
                        <tr>
                            <td><strong>{{ $controller_name }}</strong></td>
                            <td>{{ $model }}</td>
                            <td>{{ $version }}</td>
                            <td>{{ $serial }}</td>
                            <td>
                                @if($status === 'ok' || strtolower($status) === 'up')
                                    <span class="label label-success">{{ strtoupper($status) }}</span>
                                @elseif($status === 'failed' || strtolower($status) === 'down')
                                    <span class="label label-danger">{{ strtoupper($status) }}</span>
                                @else
                                    <span class="label label-warning">{{ strtoupper($status) }}</span>
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

@endif
