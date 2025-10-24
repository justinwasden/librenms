@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme"></i> <strong>Proxmox Node: {{ $device['sysName'] }}</strong>
            </div>
            <div class="panel-body">
                @php
                    // Check standard tables for REST data source (or assume everything is REST for this tab)
                    $node_metrics = DB::table('sensors')
                        ->where('device_id', $device['device_id'])
                        ->where(function ($query) {
                            $query->where('sensor_type', 'rest-api')
                                  ->orWhere('sensor_class', 'mempool');
                        })
                        ->get()
                        ->keyBy('sensor_descr');

                    // Fallback to generic metrics table if needed (though currently empty)
                    $custom_metrics = DB::table('rest_api_metrics')
                        ->where('device_id', $device['device_id'])
                        ->get()
                        ->keyBy('metric_key');
                @endphp

                <h4 class="mb-3">Health and System Metrics</h4>
                <table class="table table-condensed table-striped">
                    <tr><th>Metric</th><th>Value</th></tr>
                    <tr>
                        <td>Cluster Status (Quorate)</td>
                        <td>
                            @php
                                $status = $node_metrics['cluster_status']?->sensor_current ?? 'N/A';
                                $label = ($status == 1) ? 'success' : 'danger';
                            @endphp
                            <span class="label label-{{ $label }}">{{ ($status == 1) ? 'ONLINE' : 'OFFLINE' }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td>CPU Usage</td>
                        <td>
                            @php
                                // Processor data usually maps to the 'processors' table
                                $cpu = DB::table('processors')->where('device_id', $device['device_id'])->orderBy('processor_usage', 'desc')->first();
                            @endphp
                            {{ round($cpu->processor_usage ?? 0, 1) }}%
                        </td>
                    </tr>
                    <tr>
                        <td>Total Memory</td>
                        <td>{{ \LibreNMS\Util\Number::formatBi($node_metrics['memory_total']?->sensor_current ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td>Used Memory</td>
                        <td>{{ \LibreNMS\Util\Number::formatBi($node_metrics['memory_used']?->sensor_current ?? 0) }}</td>
                    </tr>
                </table>

                <h4 class="mb-3 mt-4">Storage Pools</h4>
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> View disk/storage utilization on the main Storage tab.
                </div>
            </div>
        </div>
    </div>
</div>
@endsection