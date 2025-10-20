{{--
    PureStorage Array Overview
    Displays comprehensive array information, performance metrics, volumes, and controllers
    Uses LibreNMS v1 layout components (x-panel)
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use App\Models\Storage;
use App\Models\EntPhysical;

$device_id = $device['device_id'];

// Get array data from REST API metrics table
$array_data = DB::table('rest_api_metrics')
    ->where('device_id', $device_id)
    ->where('resource_type', 'arrays')
    ->orderBy('last_updated', 'desc')
    ->get()
    ->keyBy('metric_key');

// Extract array metrics
$array_name = $array_data->get('name')?->metric_value ?? $device['hostname'];
$capacity = (float)($array_data->get('capacity')?->metric_value ?? 0);
$total_physical = (float)($array_data->get('space_total_physical')?->metric_value ?? 0);
$total_provisioned = (float)($array_data->get('space_total_provisioned')?->metric_value ?? 0);
$data_reduction = (float)($array_data->get('space_data_reduction')?->metric_value ?? 1.0);
$total_reduction = (float)($array_data->get('space_total_reduction')?->metric_value ?? 1.0);
$unique = (float)($array_data->get('space_unique')?->metric_value ?? 0);
$shared = (float)($array_data->get('space_shared')?->metric_value ?? 0);
$snapshots = (float)($array_data->get('space_snapshots')?->metric_value ?? 0);
$version = $array_data->get('version')?->metric_value ?? $device['version'] ?? 'Unknown';

// Get volumes from storage table (DataRouter only stores actual volumes here now)
$volumes = Storage::where('device_id', $device_id)
    ->where('type', 'rest-api')
    ->orderBy('storage_descr')
    ->get();

// Get controllers from entPhysical table (CT0, CT1 only)
$controllers = EntPhysical::where('device_id', $device_id)
    ->whereIn('entPhysicalDescr', ['CT0', 'CT1'])
    ->orderBy('entPhysicalDescr')
    ->get();

// Get performance metrics from sensors
$read_bw = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%read_bytes_per_sec%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$read_bw_val = ($read_bw?->sensor_current ?? 0) / 1024 / 1024;

$write_bw = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%write_bytes_per_sec%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$write_bw_val = ($write_bw?->sensor_current ?? 0) / 1024 / 1024;

$read_iops = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%reads_per_sec%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$read_iops_val = $read_iops?->sensor_current ?? 0;

$write_iops = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%writes_per_sec%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$write_iops_val = $write_iops?->sensor_current ?? 0;

$read_lat = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%usec_per_read_op%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$read_lat_val = ($read_lat?->sensor_current ?? 0) / 1000;

$write_lat = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%usec_per_write_op%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$write_lat_val = ($write_lat?->sensor_current ?? 0) / 1000;

$queue_read_lat = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', '%queue_usec_per_read_op%')
    ->orderBy('lastupdate', 'desc')
    ->first();
$queue_read_lat_val = ($queue_read_lat?->sensor_current ?? 0) / 1000;

$has_data = $array_data->count() > 0 || $volumes->count() > 0;

@endphp

@if(!$has_data)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>No Pure Storage data available</strong>
            <br>Data will appear after the next polling cycle.
        </div>
    </div>
</div>
@else

<!-- Array Information Panel -->
<div class="row">
    <div class="col-md-6">
        <x-panel class="device-overview panel-condensed">
            <x-slot name="heading">
                <i class="fa fa-database fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Array Information</strong>
            </x-slot>
            <table class="table table-hover table-condensed table-striped tw:mb-0!">
                <tbody>
                    <tr>
                        <td style="width: 50%; font-weight: bold;">Array Name</td>
                        <td>{{ $array_name }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Purity Version</td>
                        <td>{{ $version }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Raw Capacity</td>
                        <td>{{ Number::formatBi($capacity) }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Total Physical Space</td>
                        <td>{{ Number::formatBi($total_physical) }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Total Provisioned Space</td>
                        <td>{{ Number::formatBi($total_provisioned) }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Total Reduction</td>
                        <td>{{ number_format($total_reduction, 2) }}:1</td>
                    </tr>
                </tbody>
            </table>
        </x-panel>
    </div>

    <!-- Performance Metrics Panel -->
    <div class="col-md-6">
        <x-panel class="device-overview panel-condensed">
            <x-slot name="heading">
                <i class="fa fa-tachometer fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Performance Metrics</strong>
            </x-slot>
            <table class="table table-hover table-condensed table-striped tw:mb-0!">
                <tbody>
                    <tr>
                        <td style="width: 50%; font-weight: bold;">Read Bandwidth</td>
                        <td>{{ number_format($read_bw_val, 2) }} MB/s</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Write Bandwidth</td>
                        <td>{{ number_format($write_bw_val, 2) }} MB/s</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Read IOPs</td>
                        <td>
                            {{ number_format($read_iops_val) }}
                            @if($read_iops_val >= 1000)
                                ({{ number_format($read_iops_val/1000, 1) }}K)
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Write IOPs</td>
                        <td>
                            {{ number_format($write_iops_val) }}
                            @if($write_iops_val >= 1000)
                                ({{ number_format($write_iops_val/1000, 1) }}K)
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Avg Read Latency</td>
                        <td>{{ number_format($read_lat_val, 2) }} ms</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Avg Write Latency</td>
                        <td>{{ number_format($write_lat_val, 2) }} ms</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Queue Read Latency</td>
                        <td>{{ number_format($queue_read_lat_val, 2) }} ms</td>
                    </tr>
                </tbody>
            </table>
        </x-panel>
    </div>
</div>

<!-- Controllers Panel -->
@if($controllers->count() > 0)
<div class="row">
    <div class="col-md-12">
        <x-panel class="device-overview panel-condensed">
            <x-slot name="heading">
                <i class="fa fa-microchip fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Controllers</strong>
            </x-slot>
            <table class="table table-hover table-condensed table-striped tw:mb-0!">
                <thead>
                    <tr>
                        <th>Controller Name</th>
                        <th>Model</th>
                        <th>Status</th>
                        <th>Mode</th>
                        <th>Version</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($controllers as $controller)
                    <tr>
                        <td><strong>{{ $controller->entPhysicalDescr ?? 'Unknown' }}</strong></td>
                        <td>{{ $controller->entPhysicalModelName ?? 'N/A' }}</td>
                        <td>
                            @php
                                $status = strtolower($controller->entPhysicalOperStatus ?? 'unknown');
                            @endphp
                            @if($status === 'ready' || $status === 'ok' || $status === 'up')
                                <span class="label label-success">{{ strtoupper($controller->entPhysicalOperStatus ?? 'OK') }}</span>
                            @elseif($status === 'failed' || $status === 'down')
                                <span class="label label-danger">{{ strtoupper($controller->entPhysicalOperStatus ?? 'FAILED') }}</span>
                            @else
                                <span class="label label-warning">{{ strtoupper($controller->entPhysicalOperStatus ?? 'UNKNOWN') }}</span>
                            @endif
                        </td>
                        <td>{{ ucfirst($controller->entPhysicalClass ?? 'N/A') }}</td>
                        <td>{{ $controller->entPhysicalHardwareRev ?? 'N/A' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    </div>
</div>
@endif

<!-- Volumes Panel -->
@if($volumes->count() > 0)
<div class="row">
    <div class="col-md-12">
        <x-panel class="device-overview panel-condensed">
            <x-slot name="heading">
                <i class="fa fa-hdd-o fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Volumes</strong>
                <span class="pull-right text-muted"><small>{{ $volumes->count() }} volumes</small></span>
            </x-slot>
            <table class="table table-hover table-condensed table-striped tw:mb-0!">
                <thead>
                    <tr>
                        <th>Volume Name</th>
                        <th class="text-right">Provisioned</th>
                        <th class="text-right">Used</th>
                        <th class="text-right">Available</th>
                        <th class="text-center">Usage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($volumes as $volume)
                    <tr>
                        <td><strong>{{ $volume->storage_descr }}</strong></td>
                        <td class="text-right">{{ Number::formatBi($volume->storage_size ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($volume->storage_used ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi(($volume->storage_size ?? 0) - ($volume->storage_used ?? 0)) }}</td>
                        <td class="text-center">
                            @php
                                $perc = ($volume->storage_size && $volume->storage_size > 0) 
                                    ? round(($volume->storage_used / $volume->storage_size) * 100, 1) 
                                    : 0;
                                $label_class = $perc > 80 ? 'label-danger' : ($perc > 60 ? 'label-warning' : 'label-success');
                            @endphp
                            <span class="label {{ $label_class }}">{{ $perc }}%</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    </div>
</div>
@endif

@endif
