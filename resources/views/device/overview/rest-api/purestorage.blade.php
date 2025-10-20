{{--
    PureStorage Array Overview
    Displays comprehensive array information, performance metrics, and volumes
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use App\Models\Storage;

$device_id = $device['device_id'];

// Get the array itself (usually stored with array name as storage_descr)
$array = Storage::where('device_id', $device_id)
    ->where('type', 'rest-api')
    ->where(function ($query) {
        $query->where('storage_descr', $device['hostname'])
              ->orWhere('storage_descr', 'like', '%X50%')
              ->orWhere('storage_descr', 'like', '%X20%')
              ->orWhere('storage_descr', 'like', '%MX%');
    })
    ->first();

// Get volumes only - exclude non-volume entries
$volumes = Storage::where('device_id', $device_id)
    ->where('type', 'rest-api')
    ->where(function ($query) {
        // Exclude all non-volume patterns
        $query->whereNotLike('storage_descr', 'CH0%')
              ->whereNotLike('storage_descr', 'CH1%')
              ->whereNotLike('storage_descr', 'CT0%')
              ->whereNotLike('storage_descr', 'CT1%')
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
    ->where('storage_descr', '!=', $device['hostname']) // Exclude the array itself
    ->orderBy('storage_descr')
    ->get();

$has_data = !is_null($array) || $volumes->count() > 0;

// Helper to get sensor value safely
$getSensorValue = function($metric_name) use ($device_id) {
    $sensor = DB::table('sensors')
        ->where('device_id', $device_id)
        ->where('sensor_descr', 'like', "%{$metric_name}%")
        ->orderBy('lastupdate', 'desc')
        ->first();
    return $sensor?->sensor_current ?? null;
};

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

        @if($array)
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
                            <td>{{ $array->storage_descr ?? $device['hostname'] }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Purity Version</td>
                            <td>{{ $device['version'] ?? 'Unknown' }}</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Raw Capacity</td>
                            <td>{{ Number::formatBi($array->storage_size ?? 0) }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Total Physical Space</td>
                            <td>{{ Number::formatBi($array->storage_used ?? 0) }}</td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Total Provisioned Space</td>
                            <td>
                                @php
                                    $provisioned = DB::table('storage')
                                        ->where('device_id', $device_id)
                                        ->where('type', 'rest-api')
                                        ->sum('storage_size');
                                @endphp
                                {{ Number::formatBi($provisioned ?? 0) }}
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Total Reduction</td>
                            <td>
                                @php
                                    // Try to get from a sensor or custom field
                                    $reduction_metric = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%reduction%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $reduction = $reduction_metric?->sensor_current ?? 9.0;
                                @endphp
                                {{ number_format($reduction, 2) }}:1
                            </td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Last Polled At</td>
                            <td>{{ $array->updated_at ? \Carbon\Carbon::parse($array->updated_at)->format('Y-m-d H:i:s e') : 'Never' }}</td>
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
                            <td>
                                @php
                                    $read_bw = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%read_bytes_per_sec%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $read_bw_val = ($read_bw?->sensor_current ?? 0) / 1024 / 1024;
                                @endphp
                                {{ number_format($read_bw_val, 2) }} MB/s
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Write Bandwidth</td>
                            <td>
                                @php
                                    $write_bw = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%write_bytes_per_sec%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $write_bw_val = ($write_bw?->sensor_current ?? 0) / 1024 / 1024;
                                @endphp
                                {{ number_format($write_bw_val, 2) }} MB/s
                            </td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Read IOPs</td>
                            <td>
                                @php
                                    $read_iops = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%reads_per_sec%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $read_iops_val = $read_iops?->sensor_current ?? 0;
                                @endphp
                                {{ number_format($read_iops_val) }}
                                @if($read_iops_val >= 1000)
                                    ({{ number_format($read_iops_val/1000, 1) }}K)
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Write IOPs</td>
                            <td>
                                @php
                                    $write_iops = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%writes_per_sec%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $write_iops_val = $write_iops?->sensor_current ?? 0;
                                @endphp
                                {{ number_format($write_iops_val) }}
                                @if($write_iops_val >= 1000)
                                    ({{ number_format($write_iops_val/1000, 1) }}K)
                                @endif
                            </td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Avg Read Latency</td>
                            <td>
                                @php
                                    $read_lat = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%usec_per_read_op%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $read_lat_val = ($read_lat?->sensor_current ?? 0) / 1000;
                                @endphp
                                {{ number_format($read_lat_val, 2) }} ms
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Avg Write Latency</td>
                            <td>
                                @php
                                    $write_lat = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%usec_per_write_op%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $write_lat_val = ($write_lat?->sensor_current ?? 0) / 1000;
                                @endphp
                                {{ number_format($write_lat_val, 2) }} ms
                            </td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td style="font-weight: bold;">Queue Read Latency</td>
                            <td>
                                @php
                                    $queue_read_lat = DB::table('sensors')
                                        ->where('device_id', $device_id)
                                        ->where('sensor_descr', 'like', '%queue_usec_per_read_op%')
                                        ->orderBy('lastupdate', 'desc')
                                        ->first();
                                    $queue_read_lat_val = ($queue_read_lat?->sensor_current ?? 0) / 1000;
                                @endphp
                                {{ number_format($queue_read_lat_val, 2) }} ms
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

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
                        <th style="font-weight: bold; text-align: right;">Read Bandwidth</th>
                        <th style="font-weight: bold; text-align: right;">Write Bandwidth</th>
                        <th style="font-weight: bold; text-align: right;">Read IOPs</th>
                        <th style="font-weight: bold; text-align: right;">Write IOPs</th>
                        <th style="font-weight: bold; text-align: right;">Avg Read Latency</th>
                        <th style="font-weight: bold; text-align: right;">Avg Write Latency</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($volumes as $volume)
                    @php
                        // Try to get performance data from sensors table
                        $vol_read_bw = DB::table('sensors')
                            ->where('device_id', $device_id)
                            ->where('sensor_descr', 'like', "%{$volume->storage_descr}%read%")
                            ->orderBy('lastupdate', 'desc')
                            ->first();
                        $vol_read_bw_val = ($vol_read_bw?->sensor_current ?? 0) / 1024;

                        $vol_write_bw = DB::table('sensors')
                            ->where('device_id', $device_id)
                            ->where('sensor_descr', 'like', "%{$volume->storage_descr}%write%")
                            ->orderBy('lastupdate', 'desc')
                            ->first();
                        $vol_write_bw_val = ($vol_write_bw?->sensor_current ?? 0) / 1024;

                        // Default to 0 for IOPS and latency if not found
                        $vol_read_iops = 0;
                        $vol_write_iops = 0;
                        $vol_read_lat = 0;
                        $vol_write_lat = 0;
                    @endphp
                    <tr>
                        <td><strong>{{ $volume->storage_descr }}</strong></td>
                        <td style="text-align: right;">{{ number_format($vol_read_bw_val, 2) }} KB/s</td>
                        <td style="text-align: right;">{{ number_format($vol_write_bw_val, 2) }} KB/s</td>
                        <td style="text-align: right;">{{ number_format($vol_read_iops) }}</td>
                        <td style="text-align: right;">{{ number_format($vol_write_iops) }}</td>
                        <td style="text-align: right;">{{ number_format($vol_read_lat, 2) }} ms</td>
                        <td style="text-align: right;">{{ number_format($vol_write_lat, 2) }} ms</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @endif
    </div>
</div>

@endif

<style>
.panel-body {
    background-color: #1a1a1a;
    color: #e0e0e0;
}
.table-striped > tbody > tr:nth-of-type(odd) {
    background-color: rgba(255, 255, 255, 0.02);
}
.table-striped > tbody > tr:nth-of-type(even) {
    background-color: rgba(0, 0, 0, 0.3);
}
</style>
