{{--
    PureStorage Array Overview
    Displays comprehensive array information, performance metrics, volumes, and controllers
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use App\Models\Storage;
use App\Models\EntPhysical;

$device_id = $device['device_id'];

// Get array data from REST API metrics table (stored from /api/2.26/arrays endpoint)
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
$os = $array_data->get('os')?->metric_value ?? 'Purity//FA';

// Get volumes only - exclude non-volume entries
$volumes = Storage::where('device_id', $device_id)
    ->where('type', 'rest-api')
    ->where(function ($query) {
        // Exclude all non-volume patterns
        $query->whereNotLike('storage_descr', 'CH0%')
              ->whereNotLike('storage_descr', 'CH1%')
              ->whereNotLike('storage_descr', 'CT0%')
              ->whereNotLike('storage_descr', 'CT1%')
              ->whereNotLike('storage_descr', '%.BAY%')
              ->whereNotLike('storage_descr', '%.NVB%')
              ->whereNotLike('storage_descr', 'ITS-RSA%')
              ->whereNotLike('storage_descr', 'ALM-C%')
              ->whereNotLike('storage_descr', 'RSA-X%')
              ->whereNotLike('storage_descr', 'RSA-SW%')
              ->whereNotLike('storage_descr', 'RSA-MH%')
              ->whereNotLike('storage_descr', 'RSA-IAAS%')
              ->whereNotLike('storage_descr', 'SL-SW%')
              ->whereNotLike('storage_descr', 'SW-SQL%')
              ->whereNotLike('storage_descr', 'RSA-Druva%')
              ->whereNotLike('storage_descr', 'ALMH::%');
    })
    ->where('storage_descr', '!=', $device['hostname'])
    ->orderBy('storage_descr')
    ->get();

// Get controllers from entPhysical table (CT0, CT1 only)
$controllers = EntPhysical::where('device_id', $device_id)
    ->whereIn('entPhysicalDescr', ['CT0', 'CT1'])
    ->orderBy('entPhysicalDescr')
    ->get();

// Get performance metrics from sensors table
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

$has_data = $array_data->count() > 0;

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

<div class="panel panel-default">
    <div class="panel-heading">
        <h4 class="panel-title">
            <i class="fa fa-cube fa-lg"></i>
            <strong>PureStorage Array Overview</strong>
            <button class="btn btn-sm btn-primary pull-right" onclick="location.href='/device/device_id={{ $device_id }}'">
                <i class="fa fa-refresh"></i> Poll Now
            </button>
        </h4>
    </div>
    <div class="panel-body" style="padding: 20px;">

        <!-- Array Information and Performance Metrics Row -->
        <div class="row" style="margin-bottom: 30px;">
            <!-- Left Column: Array Information -->
            <div class="col-md-6">
                <h4 style="border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fa fa-database"></i> Array Information
                </h4>
                <table class="table table-striped" style="margin-bottom: 0;">
                    <tbody>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold; width: 50%;">Array Name</td>
                            <td>{{ $array_name }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Purity Version</td>
                            <td>{{ $version }}</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Raw Capacity</td>
                            <td>{{ Number::formatBi($capacity) }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Total Physical Space</td>
                            <td>{{ Number::formatBi($total_physical) }}</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Total Provisioned Space</td>
                            <td>{{ Number::formatBi($total_provisioned) }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Total Reduction</td>
                            <td>{{ number_format($total_reduction, 2) }}:1</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Last Polled At</td>
                            <td>{{ \Carbon\Carbon::now()->format('Y-m-d H:i:s e') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Right Column: Performance Metrics -->
            <div class="col-md-6">
                <h4 style="border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fa fa-tachometer"></i> Performance Metrics
                </h4>
                <table class="table table-striped" style="margin-bottom: 0;">
                    <tbody>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold; width: 50%;">Read Bandwidth</td>
                            <td>{{ number_format($read_bw_val, 2) }} MB/s</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Write Bandwidth</td>
                            <td>{{ number_format($write_bw_val, 2) }} MB/s</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
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
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Avg Read Latency</td>
                            <td>{{ number_format($read_lat_val, 2) }} ms</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Avg Write Latency</td>
                            <td>{{ number_format($write_lat_val, 2) }} ms</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Queue Read Latency</td>
                            <td>{{ number_format($queue_read_lat_val, 2) }} ms</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Controllers Section -->
        @if($controllers->count() > 0)
        <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd;">
            <h4 style="margin-bottom: 15px;">
                <i class="fa fa-microchip"></i> Controllers
            </h4>
            <table class="table table-striped table-hover" style="margin-bottom: 20px;">
                <thead style="background-color: #f5f5f5;">
                    <tr>
                        <th style="font-weight: bold;">Controller Name</th>
                        <th style="font-weight: bold;">Model</th>
                        <th style="font-weight: bold;">Status</th>
                        <th style="font-weight: bold;">Mode</th>
                        <th style="font-weight: bold;">Version</th>
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
        </div>
        @endif

        <!-- Volumes Section -->
        @if($volumes->count() > 0)
        <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd;">
            <h4 style="margin-bottom: 15px;">
                <i class="fa fa-hdd-o"></i> Volumes
            </h4>
            <table class="table table-striped table-hover" style="margin-bottom: 0;">
                <thead style="background-color: #f5f5f5;">
                    <tr>
                        <th style="font-weight: bold;">Volume Name</th>
                        <th style="font-weight: bold; text-align: right;">Provisioned</th>
                        <th style="font-weight: bold; text-align: right;">Used</th>
                        <th style="font-weight: bold; text-align: right;">Available</th>
                        <th style="font-weight: bold; text-align: center;">Usage %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($volumes as $volume)
                    <tr>
                        <td><strong>{{ $volume->storage_descr }}</strong></td>
                        <td style="text-align: right;">{{ Number::formatBi($volume->storage_size ?? 0) }}</td>
                        <td style="text-align: right;">{{ Number::formatBi($volume->storage_used ?? 0) }}</td>
                        <td style="text-align: right;">{{ Number::formatBi(($volume->storage_size ?? 0) - ($volume->storage_used ?? 0)) }}</td>
                        <td style="text-align: center;">
                            @php
                                $perc = ($volume->storage_size && $volume->storage_size > 0)
                                    ? round(($volume->storage_used / $volume->storage_size) * 100, 1)
                                    : 0;
                                $perc_color = $perc > 80 ? '#d9534f' : ($perc > 60 ? '#f0ad4e' : '#5cb85c');
                            @endphp
                            <span style="background-color: {{ $perc_color }}; color: white; padding: 2px 8px; border-radius: 3px; display: inline-block;">
                                {{ $perc }}%
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>

@endif

<style>
.panel-body {
    background-color: transparent;
}
.table-striped > tbody > tr:nth-of-type(odd) {
    background-color: rgba(0, 0, 0, 0.02);
}
.table-striped > tbody > tr:nth-of-type(even) {
    background-color: #fff;
}
.table-striped > tbody > tr:hover {
    background-color: #f5f5f5;
}
</style>
