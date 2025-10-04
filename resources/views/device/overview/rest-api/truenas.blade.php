{{--
    TrueNAS REST API Overview
    Displays storage pools, datasets, shares, and system health
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get system information
$system_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'system')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get pool information
$pools = DB::table('device_api_metrics')
    ->select([
        'resource_name', 'resource_id',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status'),
        DB::raw('MAX(CASE WHEN metric_name = "size" THEN value END) as size'),
        DB::raw('MAX(CASE WHEN metric_name = "allocated" THEN value END) as allocated'),
        DB::raw('MAX(CASE WHEN metric_name = "free" THEN value END) as free'),
        DB::raw('MAX(CASE WHEN metric_name = "fragmentation" THEN value END) as fragmentation'),
        DB::raw('MAX(CASE WHEN metric_name = "health" THEN string_value END) as health')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'pool')
    ->groupBy('resource_name', 'resource_id')
    ->get();

// Get dataset information
$datasets = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "used" THEN value END) as used'),
        DB::raw('MAX(CASE WHEN metric_name = "available" THEN value END) as available'),
        DB::raw('MAX(CASE WHEN metric_name = "compression_ratio" THEN value END) as compression_ratio'),
        DB::raw('MAX(CASE WHEN metric_name = "type" THEN string_value END) as type')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'dataset')
    ->groupBy('resource_name')
    ->orderBy('used', 'desc')
    ->limit(10)
    ->get();

// Get share information
$shares = DB::table('device_api_metrics')
    ->select([
        'resource_name', 'resource_type',
        DB::raw('MAX(CASE WHEN metric_name = "path" THEN string_value END) as path'),
        DB::raw('MAX(CASE WHEN metric_name = "enabled" THEN string_value END) as enabled'),
        DB::raw('MAX(CASE WHEN metric_name = "type" THEN string_value END) as share_type')
    ])
    ->where('device_id', $device['device_id'])
    ->whereIn('resource_type', ['nfs-share', 'smb-share', 'iscsi-share'])
    ->groupBy('resource_name', 'resource_type')
    ->get();

// Get replication tasks
$replications = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "state" THEN string_value END) as state'),
        DB::raw('MAX(CASE WHEN metric_name = "direction" THEN string_value END) as direction'),
        DB::raw('MAX(CASE WHEN metric_name = "last_run" THEN string_value END) as last_run'),
        DB::raw('MAX(collected_at) as last_update')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'replication')
    ->groupBy('resource_name')
    ->get();

$cpu_util = $system_metrics['cpu_usage']->first()->value ?? 0;
$mem_total = $system_metrics['memory_total']->first()->value ?? 0;
$mem_used = $system_metrics['memory_used']->first()->value ?? 0;
$mem_percent = $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 2) : 0;
$cpu_bg = \LibreNMS\Util\Color::percentage($cpu_util, 70);
$mem_bg = \LibreNMS\Util\Color::percentage($mem_percent, 80);

$share_type_map = [
    'nfs-share' => 'NFS',
    'smb-share' => 'SMB',
    'iscsi-share' => 'iSCSI'
];
@endphp

<!-- System Health -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd fa-lg icon-theme"></i> <strong>TrueNAS System Health</strong>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-4">
                        <table class="table table-condensed">
                            <tr><th>Hostname</th><td>{{ $system_metrics['hostname']->first()->string_value ?? $device['hostname'] }}</td></tr>
                            <tr><th>Version</th><td>{{ $system_metrics['version']->first()->string_value ?? 'N/A' }}</td></tr>
                            <tr><th>Uptime</th><td>{{ floor(($system_metrics['uptime']->first()->value ?? 0) / 86400) }}d {{ floor((($system_metrics['uptime']->first()->value ?? 0) % 86400) / 3600) }}h</td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h4>CPU Usage</h4>
                        {!! print_percentage_bar(300, 40, $cpu_util, $cpu_util . "%", 'ffffff', $cpu_bg['left'], 100 - $cpu_util, 'ffffff', $cpu_bg['right']) !!}
                    </div>
                    <div class="col-md-4">
                        <h4>Memory Usage</h4>
                        {!! print_percentage_bar(300, 40, $mem_percent, Number::formatBi($mem_used) . " / " . Number::formatBi($mem_total), 'ffffff', $mem_bg['left'], $mem_total - $mem_used, 'ffffff', $mem_bg['right']) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($pools->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i> <strong>Storage Pools</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Pool Name</th><th>Status</th><th>Health</th><th>Total Size</th>
                        <th>Allocated</th><th>Free</th><th>Utilization</th><th class="text-right">Fragmentation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pools as $pool)
                    @php
                        $pool_percent = $pool->size > 0 ? round(($pool->allocated / $pool->size) * 100, 2) : 0;
                        $pool_bg = \LibreNMS\Util\Color::percentage($pool_percent, 80);
                    @endphp
                    <tr>
                        <td><strong>{{ $pool->resource_name }}</strong></td>
                        <td><span class="label label-{{ ($pool->status == 'ONLINE') ? 'success' : 'danger' }}">{{ $pool->status ?? 'UNKNOWN' }}</span></td>
                        <td><span class="label label-{{ ($pool->health == 'HEALTHY') ? 'success' : 'warning' }}">{{ $pool->health ?? 'UNKNOWN' }}</span></td>
                        <td>{{ Number::formatBi($pool->size ?? 0) }}</td>
                        <td>{{ Number::formatBi($pool->allocated ?? 0) }}</td>
                        <td>{{ Number::formatBi($pool->free ?? 0) }}</td>
                        <td>{!! print_percentage_bar(200, 20, $pool_percent, $pool_percent . "%", 'ffffff', $pool_bg['left'], 100 - $pool_percent, 'ffffff', $pool_bg['right']) !!}</td>
                        <td class="text-right">{{ number_format($pool->fragmentation ?? 0) }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="row">
    @if($datasets->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-folder fa-lg icon-theme"></i> <strong>Top Datasets</strong>
                <span class="pull-right text-muted">By usage</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Dataset</th><th>Type</th><th class="text-right">Used</th><th class="text-right">Compression</th></tr></thead>
                <tbody>
                    @foreach($datasets as $dataset)
                    <tr>
                        <td class="small">{{ $dataset->resource_name }}</td>
                        <td><span class="label label-info">{{ strtoupper($dataset->type ?? 'DATASET') }}</span></td>
                        <td class="text-right">{{ Number::formatBi($dataset->used ?? 0) }}</td>
                        <td class="text-right">{{ number_format($dataset->compression_ratio ?? 1, 2) }}x</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($shares->count() > 0)
    <div class="col-md-6">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-share-alt fa-lg icon-theme"></i> <strong>Network Shares</strong>
                <span class="badge pull-right">{{ $shares->count() }}</span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Share Name</th><th>Type</th><th>Path</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($shares as $share)
                    <tr>
                        <td><strong>{{ $share->resource_name }}</strong></td>
                        <td><span class="label label-primary">{{ $share_type_map[$share->resource_type] ?? strtoupper($share->share_type ?? 'N/A') }}</span></td>
                        <td class="text-muted small">{{ substr($share->path ?? '', 0, 40) }}</td>
                        <td><span class="label label-{{ ($share->enabled == 'true') ? 'success' : 'default' }}">{{ ($share->enabled == 'true') ? 'ENABLED' : 'DISABLED' }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

@if($replications->count() > 0)
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-sync fa-lg icon-theme"></i> <strong>Replication Tasks</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead><tr><th>Task Name</th><th>Direction</th><th>State</th><th>Last Run</th><th>Last Update</th></tr></thead>
                <tbody>
                    @foreach($replications as $repl)
                    <tr>
                        <td>{{ $repl->resource_name }}</td>
                        <td><span class="label label-info">{{ strtoupper($repl->direction ?? 'N/A') }}</span></td>
                        <td><span class="label label-{{ ($repl->state == 'FINISHED') ? 'success' : (($repl->state == 'RUNNING') ? 'warning' : 'default') }}">{{ $repl->state ?? 'UNKNOWN' }}</span></td>
                        <td class="text-muted small">{{ $repl->last_run ?? 'Never' }}</td>
                        <td class="text-muted small">{{ \Carbon\Carbon::parse($repl->last_update)->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
