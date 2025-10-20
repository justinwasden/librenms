{{--
    PureStorage FlashArray REST API Overview
    Displays array capacity, performance metrics, and hardware status
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Color;
use App\Models\Storage;

$device_id = $device['device_id'];

// Query storage table for Pure Storage array - filter to get only volumes
// Volumes are identified by NOT matching hardware patterns
$storages = Storage::where('device_id', $device_id)
    ->where('type', 'rest-api')
    ->where(function ($query) {
        // Include only actual volumes - exclude hardware, drives, hosts, chassis
        $query->whereNotLike('storage_descr', 'CH0%')     // Chassis
              ->whereNotLike('storage_descr', 'CH1%')
              ->whereNotLike('storage_descr', 'CT0%')     // Controllers
              ->whereNotLike('storage_descr', 'CT1%')
              ->whereNotLike('storage_descr', 'ITS-RSA%') // ESXi hosts
              ->whereNotLike('storage_descr', 'ALM%')     // Other hosts
              ->whereNotLike('storage_descr', 'RSA-X%')   // Other hosts
              ->whereNotLike('storage_descr', 'RSA-SW%')  // Software
              ->whereNotLike('storage_descr', 'RSA-MH%')  // Other
              ->whereNotLike('storage_descr', 'RSA-IAAS%') // IaaS
              ->whereNotLike('storage_descr', 'SL-SW%')   // Software
              ->whereNotLike('storage_descr', 'SW-SQL%')  // Software
              ->whereNotLike('storage_descr', 'RSA-Druva%') // Apps
              ->whereNotLike('storage_descr', 'ALMH%');   // Volumes (ALMH namespace)
    })
    ->orderBy('storage_descr')
    ->get();

// For array-level metrics, look for the array name entry
$array_storage = Storage::where('device_id', $device_id)
    ->where('storage_descr', $device['hostname'])
    ->orWhere('storage_descr', 'like', '%X50%')
    ->first();

// Extract array metrics if available
$capacity = $array_storage?->storage_size ?? 0;
$total_used = $array_storage?->storage_used ?? 0;
$total_perc = $array_storage?->storage_perc ?? 0;

// Try to get data reduction ratio from storage field if it exists
$data_reduction = 1.0;
if ($array_storage && property_exists($array_storage, 'data_reduction')) {
    $data_reduction = $array_storage->data_reduction ?? 1.0;
}

// Check if we have a Pure Storage array configured
$has_data = !is_null($array_storage) || $storages->count() > 0;

@endphp

@if(!$has_data)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>No Pure Storage data available</strong>
            <br>Data will appear after the next polling cycle. Ensure:
            <ul class="small" style="margin-top: 5px; margin-bottom: 0;">
                <li>The REST API poller is active</li>
                <li>Pure Storage API credentials are configured</li>
                <li>The device has completed at least one full polling cycle</li>
            </ul>
        </div>
    </div>
</div>
@else

<!-- Array Storage Overview Panel -->
@if($array_storage)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i>
                <strong>Array Storage Metrics</strong>
                <span class="pull-right text-muted small">{{ $array_storage->storage_descr }}</span>
            </div>
            <div class="panel-body">
                <div class="row">
                    <!-- Array Info Table -->
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Array Name</th>
                                <td><strong>{{ $array_storage->storage_descr }}</strong></td>
                            </tr>
                            <tr>
                                <th>Total Capacity</th>
                                <td>{{ Number::formatBi($capacity) }}</td>
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
                            $background = Color::percentage($total_perc, null);
                        @endphp
                        {!! print_percentage_bar(
                            400,
                            40,
                            $total_perc,
                            Number::formatBi($total_used) . ' / ' . Number::formatBi($capacity),
                            'ffffff',
                            $background['left'],
                            Number::formatBi($capacity - $total_used),
                            'ffffff',
                            $background['right']
                        ) !!}

                        @if($data_reduction > 1)
                        <p class="text-muted small" style="margin-top: 10px;">
                            <i class="fa fa-compress"></i> Data reduction ratio: <strong>{{ number_format($data_reduction, 2) }}:1</strong>
                        </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Volumes Table -->
@if($storages->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme"></i>
                <strong>Volumes</strong>
                <span class="badge pull-right">{{ $storages->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Volume Name</th>
                        <th class="text-right">Provisioned</th>
                        <th class="text-right">Used</th>
                        <th class="text-right">Available</th>
                        <th class="text-center">Usage %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($storages as $storage)
                    <tr>
                        <td><strong>{{ $storage->storage_descr }}</strong></td>
                        <td class="text-right">{{ Number::formatBi($storage->storage_size ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi($storage->storage_used ?? 0) }}</td>
                        <td class="text-right">{{ Number::formatBi(($storage->storage_size ?? 0) - ($storage->storage_used ?? 0)) }}</td>
                        <td class="text-center">
                            @php
                                $perc = ($storage->storage_size && $storage->storage_size > 0) 
                                    ? round(($storage->storage_used / $storage->storage_size) * 100, 1) 
                                    : 0;
                                $perc_bg = Color::percentage($perc, null);
                            @endphp
                            <span style="background-color: {{ $perc_bg['left'] }}; color: white; padding: 2px 6px; border-radius: 3px;">
                                {{ $perc }}%
                            </span>
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
