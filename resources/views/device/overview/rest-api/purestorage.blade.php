{{--
    PureStorage FlashArray REST API Overview
    Displays array capacity and hardware components (controllers).
    Updated to use native LibreNMS tables: 'storage' and 'entPhysical'.

    NOTE: The 'Volumes' and 'Host Connections' sections have been removed
    as their required data fields (e.g., total_provisioned, data_reduction,
    iqns, connection_count) do not exist in generic LibreNMS tables.
--}}

@php
use App\Models\Storage; // Corresponds to the native 'storage' table
use LibreNMS\Util\Number;

$device_id = $device['device_id'];

// 1. Get Array Information (Capacity and top-level metrics)
// We assume the aggregate array data is stored as a single entry in the native 'storage' table.
$storageEntry = Storage::where('device_id', $device_id)
    ->orderBy('storage_size', 'desc') // Assume the largest storage entry is the array total
    ->first();

// 2. Get Controllers (from native 'entPhysical' table)
// Explicitly referencing the full namespace path to the Entity Model.
$controllers = \LibreNMS\Entities\Entity::where('device_id', $device_id)
    ->whereIn('entPhysicalClass', ['chassis', 'module', 'processor', 'container'])
    ->get();

// Calculate totals if storage data exists
// Native fields are 'storage_size' (total) and 'storage_used' (used)
$capacity_total = $storageEntry->storage_size ?? 0;
$capacity_used = $storageEntry->storage_used ?? 0;
$capacity_percent = $capacity_total > 0 ? round(($capacity_used / $capacity_total) * 100, 2) : 0;

// Re-using old custom fields (e.g., data_reduction). If the poller is configured to write these
// to the 'storage' table, they will be available here. Otherwise, they will default to 0.
$data_reduction = $storageEntry->data_reduction ?? 0;
$total_reduction = $storageEntry->total_reduction ?? 0;

$array_name = $storageEntry->storage_descr ?? $device['hostname'];

$background = \LibreNMS\Util\Color::percentage($capacity_percent, null);

// Check if we have any data
$has_data = $storageEntry || $controllers->count() > 0;
@endphp

@if(!$has_data)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>No Storage Array data found in native tables.</strong>
            Data will appear after the next polling cycle.
            <br><small>Please ensure:</small>
            <ul class="small" style="margin-top: 5px; margin-bottom: 0;">
                <li>The REST API poller is active and correctly mapping array capacity to the `storage` table.</li>
                <li>Controller hardware is correctly mapped to the `entPhysical` table.</li>
            </ul>
        </div>
    </div>
</div>
@else

<!-- Array Storage Overview -->
@if($storageEntry)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i>
                <strong>Array Storage Metrics</strong>
                <span class="pull-right text-muted small">
                    LibreNMS Storage ID: {{ $storageEntry->storage_id }}
                </span>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-condensed table-striped">
                            <tr>
                                <th class="col-md-6">Array Name</th>
                                <td>{{ $array_name }}</td>
                            </tr>
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
                            {{-- These fields are likely custom, but kept in case poller writes them to 'storage' table --}}
                            @if($data_reduction > 0)
                            <tr>
                                <th>Data Reduction</th>
                                <td><span class="label label-success">{{ number_format($data_reduction, 2) }}:1</span></td>
                            </tr>
                            @endif
                            @if($total_reduction > 0)
                            <tr>
                                <th>Total Reduction</th>
                                <td><span class="label label-success">{{ number_format($total_reduction, 2) }}:1</span></td>
                            </tr>
                            @endif
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5><strong>Capacity Utilization</strong></h5>
                        {!! print_percentage_bar(400, 40, $capacity_percent, Number::formatBi($capacity_used) . " / " . Number::formatBi($capacity_total), 'ffffff', $background['left'], Number::formatBi($capacity_total - $capacity_used), 'ffffff', $background['right']) !!}

                        @if($data_reduction > 1)
                        <p class="text-muted small" style="margin-top: 10px;">
                            <i class="fa fa-compress"></i> Estimated effective capacity savings due to reduction.
                        </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Controllers (Native entPhysical) -->
@if($controllers->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-microchip fa-lg icon-theme"></i>
                <strong>Controllers (Hardware Entities)</strong>
                <span class="badge pull-right">{{ $controllers->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Model</th>
                        <th>Version</th>
                        <th>Serial</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($controllers as $controller)
                    <tr>
                        <td><strong>{{ $controller->entPhysicalDescr }}</strong></td>
                        <td>{{ $controller->entPhysicalClass }}</td>
                        <td>{{ $controller->entPhysicalModelName ?? 'N/A' }}</td>
                        <td>{{ $controller->entPhysicalHardwareRev ?? 'N/A' }}</td>
                        <td>{{ $controller->entPhysicalSerialNum ?? 'N/A' }}</td>
                        <td>
                            @if(isset($controller->entPhysicalOperStatus))
                                @if($controller->entPhysicalOperStatus === 'up' || $controller->entPhysicalOperStatus === 'ok')
                                    <span class="label label-success">{{ strtoupper($controller->entPhysicalOperStatus) }}</span>
                                @elseif($controller->entPhysicalOperStatus === 'down' || $controller->entPhysicalOperStatus === 'degraded')
                                    <span class="label label-danger">{{ strtoupper($controller->entPhysicalOperStatus) }}</span>
                                @else
                                    <span class="label label-warning">{{ strtoupper($controller->entPhysicalOperStatus) }}</span>
                                @endif
                            @else
                                <span class="label label-default">UNKNOWN</span>
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
