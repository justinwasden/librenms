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

// 2. Storage data
$storage = DB::table('storage')
    ->where('device_id', $device_id)
    ->where('storage_type', 'rest-api')
    ->orderBy('storage_descr')
    ->get();

// Determine if we have any valid data to show
$has_metrics = $storage->count() > 0;

@endphp


{{-- TOP SYSTEM METRICS ROW --}}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme"></i> <strong>Mapped Storage Pools/Datasets</strong>
            </div>
              <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Node Overview</th>
                        <th>Type</th>
                        <th class="text-right">Node Name</th>
                        <th class="text-right">Hostname</th>
                        <th class="text-right">Cluster Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>{{ $item->storage_descr }}</strong></td>
                        <td>{{$node_name}}</td>
                        <td {{$hostname}}</td>
                        <td>
                        	<p class="text-muted">
                        		@php
													            @if(isset($cluster_status))
													                @if(strtolower($cluster_status) === 'online' || strtolower($cluster_status) === 'active')
													                    <span class="badge bg-success">{{ ucfirst($cluster_status) }}</span>
													                @elseif(strtolower($cluster_status) === 'offline')
													                    <span class="badge bg-danger">{{ ucfirst($cluster_status) }}</span>
													                @else
													                    <span class="badge bg-secondary">{{ ucfirst($cluster_status) }}</span>
													                @endif
													            @else
													                <span class="badge bg-secondary">Unknown</span>
													            @endif
													  @endphp
													        </p></td>

                </tbody>
            </table>



{{-- Node Overview (3 Columns) --}}
<div class="row text-center">
    {{-- Node Name --}}
    <div class="col-md-4">
        <h4>Node Name</h4>
        <p class="text-muted">{{ $node_name ?? 'N/A' }}</p>
    </div>

    {{-- Hostname --}}
    <div class="col-md-4">
        <h4>Hostname</h4>
        <p class="text-muted">{{ $hostname ?? 'N/A' }}</p>
    </div>

    {{-- Cluster Status --}}
    <div class="col-md-4">
        <h4>Cluster Status</h4>
        <p class="text-muted">
            @if(isset($cluster_status))
                @if(strtolower($cluster_status) === 'online' || strtolower($cluster_status) === 'active')
                    <span class="badge bg-success">{{ ucfirst($cluster_status) }}</span>
                @elseif(strtolower($cluster_status) === 'offline')
                    <span class="badge bg-danger">{{ ucfirst($cluster_status) }}</span>
                @else
                    <span class="badge bg-secondary">{{ ucfirst($cluster_status) }}</span>
                @endif
            @else
                <span class="badge bg-secondary">Unknown</span>
            @endif
        </p>
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

