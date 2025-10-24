{{-- resources/views/device/overview/rest-api/proxmox.blade.php --}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Color;

// Device is loaded by the poller, sysName is confirmed to be the node name (e.g., c2s8-usa-esxi)

// 1. Fetch System/Cluster Metrics (Assuming they map to sensors for simplicity/status, or devices table)
$cluster_status = DB::table('sensors')
    ->where('device_id', $device['device_id'])
    ->where('sensor_descr', 'like', 'cluster_status')
    ->first();

// 2. Fetch Memory Data from mempools (Confirmed working via database query)
$mem_data = DB::table('mempools')
    ->where('device_id', $device['device_id'])
    ->where('mempool_descr', 'LIKE', 'Physical memory (system)%')
    ->first();

$total_mem = ($mem_data->mempool_used ?? 0) + ($mem_data->mempool_free ?? 0);
$used_mem = $mem_data->mempool_used ?? 0;
$mem_percent = ($total_mem > 0) ? round(($used_mem / $total_mem) * 100, 2) : 0;
$mem_bg = Color::percentage($mem_percent, 80);

// 3. Fetch CPU Data from processors
$cpu_data = DB::table('processors')
    ->where('device_id', $device['device_id'])
    ->orderBy('processor_usage', 'desc')
    ->first();
$cpu_util = $cpu_data->processor_usage ?? 0;
$cpu_bg = Color::percentage($cpu_util, 70);

// 4. Fetch Storage data (if required, querying storage table)
$storage = DB::table('storage')
    ->where('device_id', $device['device_id'])
    ->where('storage_type', 'rest-api')
    ->orderBy('storage_descr')
    ->get();

@endphp

<x-device.overview-wrap :device="$device">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">
                    <i class="fa fa-server fa-lg icon-theme"></i> <strong>Proxmox Node Overview</strong>
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-md-4">
                            <table class="table table-condensed table-striped">
                                <tr><th>Node Name</th><td>{{ $device['sysName'] }}</td></tr>
                                <tr><th>Hostname</th><td>{{ $device['hostname'] }}</td></tr>
                                <tr><th>Cluster Status</th>
                                    <td>
                                        @php
                                            // Assuming 1 = Quorate/Online, 0 = Offline
                                            $status_value = $cluster_status->sensor_current ?? 'N/A';
                                            $status_text = ($status_value == 1) ? 'ONLINE' : (($status_value === 0) ? 'OFFLINE' : 'N/A');
                                            $label = ($status_value == 1) ? 'success' : 'danger';
                                        @endphp
                                        <span class="label label-{{ $label }}">{{ $status_text }}</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-4">
                            <h4>CPU Utilization</h4>
                            {!! print_percentage_bar(350, 40, $cpu_util, Number::format($cpu_util, 1) . "%", 'ffffff', $cpu_bg['left'], 100 - $cpu_util, 'ffffff', $cpu_bg['right']) !!}
                            <p class="text-muted small text-center mt-2">
                                *Max observed usage across all cores (via REST/SNMP)*
                            </p>
                        </div>

                        <div class="col-md-4">
                            <h4>Physical Memory Usage</h4>
                            {!! print_percentage_bar(350, 40, $mem_percent, Number::formatBi($used_mem) . " / " . Number::formatBi($total_mem), 'ffffff', $mem_bg['left'], $total_mem - $used_mem, 'ffffff', $mem_bg['right']) !!}
                            <p class="text-muted small text-center mt-2">
                                {{ Number::format($mem_percent, 2) }}% Utilization
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                            <td class="text-right">{{ Number::formatBi($item->storage_size ?? 0) }}</td>
                            <td class="text-right">{{ Number::formatBi($item->storage_used ?? 0) }}</td>
                            <td class="text-right">{{ Number::formatBi(($item->storage_size ?? 0) - ($item->storage_used ?? 0)) }}</td>
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
</x-device.overview-wrap>