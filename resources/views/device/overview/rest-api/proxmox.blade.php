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
    ->value('sensor_current'); // get value directly

// 2. Storage data
$storage = DB::table('storage')
    ->where('device_id', $device_id)
    ->where('storage_type', 'rest-api')
    ->orderBy('storage_descr')
    ->get();
@endphp

{{-- TOP SYSTEM METRICS ROW --}}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme"></i> <strong>Node Overview</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th class="text-left">Node Name</th>
                        <th class="text-center">Hostname</th>
                        <th class="text-center">Cluster Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $node_name ?? $device['sysName'] ?? 'N/A' }}</td>
                        <td class="text-center">{{ $hostname ?? $device['hostname'] ?? 'N/A' }}</td>
                        <td class="text-center">
                            <p class="text-muted">
                                @if(isset($cluster_status))
                                    @if(strtolower($cluster_status) === 'online' || strtolower($cluster_status) === 'active' || $cluster_status == 1)
                                        <span class="badge bg-success">{{ ucfirst($cluster_status) }}</span>
                                    @elseif(strtolower($cluster_status) === 'offline' || $cluster_status == 0)
                                        <span class="badge bg-danger">{{ ucfirst($cluster_status) }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ ucfirst($cluster_status) }}</span>
                                    @endif
                                @else
                                    <span class="badge bg-secondary">Unknown</span>
                                @endif
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
                        @php
                            $perc = ($item->storage_size > 0) ? round(($item->storage_used / $item->storage_size) * 100, 1) : 0;
                            $label_class = ($perc > 80) ? 'label-danger' : (($perc > 60) ? 'label-warning' : 'label-success');
                        @endphp
                        <tr>
                            <td><strong>{{ $item->storage_descr }}</strong></td>
                            <td><span class="badge badge-secondary">{{ strtoupper($item->storage_type ?? 'ZFS') }}</span></td>
                            <td class="text-right">{{ \LibreNMS\Util\Number::formatBi($item->storage_size ?? 0) }}</td>
                            <td class="text-right">{{ \LibreNMS\Util\Number::formatBi($item->storage_used ?? 0) }}</td>
                            <td class="text-right">{{ \LibreNMS\Util\Number::formatBi(($item->storage_size ?? 0) - ($item->storage_used ?? 0)) }}</td>
                            <td class="text-center">
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