{{-- resources/views/device/overview/rest-api/proxmox.blade.php --}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Color;
use App\Models\Storage;
use App\Models\EntPhysical;

$device_id = $device['device_id'];

// 1. Cluster Status
$cluster_status = DB::table('sensors')
    ->where('device_id', $device_id)
    ->where('sensor_descr', 'like', 'cluster_status')
    ->first();

// 2. Memory Data from mempools (FIXED: Using broad LIKE search for "memory" to find correct row)
$mem_data = DB::table('mempools')
    ->where('device_id', $device_id)
    ->where('mempool_descr', 'LIKE', '%memory%') // Use a general search to capture Physical memory (system)
    ->first();

$total_mem = ($mem_data->mempool_used ?? 0) + ($mem_data->mempool_free ?? 100);
$used_mem = $mem_data->mempool_used ?? 0;
$mem_percent = ($total_mem > 0) ? round(($used_mem / $total_mem) * 100, 2) : 0;
$mem_bg = Color::percentage($mem_percent, 80);

// 3. CPU Data from processors
$cpu_data = DB::table('processors')
    ->where('device_id', $device_id)
    ->orderBy('processor_usage', 'desc')
    ->first();
$cpu_util = $cpu_data->processor_usage ?? 100;
$cpu_bg = Color::percentage($cpu_util, 70);

// 4. Storage data
$storage = DB::table('storage')
    ->where('device_id', $device_id)
    ->where('storage_type', 'rest-api')
    ->orderBy('storage_descr')
    ->get();

// Determine if we have any valid data to show
$has_metrics = $mem_data || $cpu_data || $storage->count() > 0;

$cpu_util_formatted = number_format($cpu_util, 1);
$mem_percent_formatted = number_format($mem_percent, 2);

@endphp

@if(!$has_metrics)
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>No REST API metrics found for Proxmox.</strong>
            <br>Ensure endpoints are configured and polled successfully.
        </div>
    </div>
</div>
@else

{{-- TOP SYSTEM METRICS ROW --}}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i>
                <strong>Proxmox Node Overview: {{ $device['sysName'] }}</strong>
            </div>

            <div class="panel-body">
                <div class="row">

                    {{-- Node Info & Status --}}
                    <div class="col-md-4">
                        <table class="table table-condensed table-striped">
                            <tbody>
                                <tr>
                                    <th class="text-right">Node Name</th>
                                    <td>{{ $device['sysName'] }}</td>
                                </tr>
                                <tr>
                                    <th class="text-right">Hostname</th>
                                    <td>{{ $device['hostname'] }}</td>
                                </tr>
                                <tr>
                                    <th class="text-right">Cluster Status</th>
                                    <td>
                                        @php
                                            $status_value = $cluster_status->sensor_current ?? 'N/A';
                                            $status_text = ($status_value == 1) ? 'ONLINE' : (($status_value === 0) ? 'OFFLINE' : 'N/A');
                                            $label = ($status_value == 1) ? 'success' : 'danger';
                                        @endphp
                                        <span class="label label-{{ $label }}">{{ $status_text }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

											{{-- CPU Utilization (Centered) --}}
											<div class="col-md-4 text-center">
											    <h4>CPU Utilization</h4>
											    <div class="text-center" style="width: 100%;">
											        <div style="display: inline-block; float: none !important; text-align: center;">
											            {!! print_percentage_bar(
											                200,
											                20,
											                $cpu_util,
											                '',  {{-- no internal text to avoid overlap --}}
											                'ffffff',
											                $cpu_bg['left'],
											                100 - $cpu_util,
											                'ffffff',
											                $cpu_bg['right']
											            ) !!}
											        </div>
											    </div>
											    <p class="text-muted small mt-2">{{ $cpu_util_formatted }}% Max/Avg Usage</p>
											</div>

											{{-- Physical Memory Usage (Centered) --}}
											<div class="col-md-4 text-center">
											    <h4>Physical Memory Usage</h4>
											    @if($total_mem > 0)
											        <div class="text-center" style="width: 100%;">
											            <div style="display: inline-block; float: none !important; text-align: center;">
											                {!! print_percentage_bar(
											                    200,
											                    20,
											                    $mem_percent,
											                    '', {{-- blank to suppress internal overlapping label --}}
											                    'ffffff',
											                    $mem_bg['left'],
											                    100 - $mem_percent,
											                    'ffffff',
											                    $mem_bg['right']
											                ) !!}
											            </div>
											        </div>
											        <p class="text-muted small mt-2">
											            {{ \LibreNMS\Util\Number::formatBi($used_mem) }} / {{ \LibreNMS\Util\Number::formatBi($total_mem) }}
											            ({{ $mem_percent_formatted }}% Utilization)
											        </p>
											    @else
											        <p class="text-muted">Memory data not available in mempools table.</p>
											    @endif
											</div>

                </div> {{-- /row --}}
            </div> {{-- /panel-body --}}
        </div> {{-- /panel --}}
    </div> {{-- /col-md-12 --}}
</div> {{-- /row --}}

{{-- STORAGE DATA ROW --}}
@if($storage->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme"></i> <strong>Mapped Storage Pools/Datasets</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Storage Description</th>
                        <th>Type</th>
                        <th class="text-right">Total Size</th>
                        <th class="text-right">Used Space</th>
                        <th class="text-right">Free Space</th>
                        <th class="text-center">Usage %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($storage as $item)
                    <tr>
                        <td><strong>{{ $item->storage_descr }}</strong></td>
                        <td><span class="badge badge-secondary">{{ strtoupper($item->storage_type ?? 'ZFS') }}</span></td>
                        <td class="text-right">{{ \LibreNMS\Util\Number::formatBi($item->storage_size ?? 0) }}</td>
                        <td class="text-right">{{ \LibreNMS\Util\Number::formatBi($item->storage_used ?? 0) }}</td>
                        <td class="text-right">{{ \LibreNMS\Util\Number::formatBi(($item->storage_size ?? 0) - ($item->storage_used ?? 0)) }}</td>
                        <td class="text-center">
                            @php
                                $perc = ($item->storage_size > 0) ? round(($item->storage_used / $item->storage_size) * 100, 1) : 0;
                                $label_class = ($perc > 80) ? 'label-danger' : (($perc > 60) ? 'label-warning' : 'label-success');
                            @endphp
                            <span class="label {{ $label_class }}">{{ $perc }}%</span>
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