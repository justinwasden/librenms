{{--
    Generic REST API Metrics Overview
    Auto-discovers and displays metrics for any REST API-enabled device
--}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get all resource types for this device
$resource_types = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->select('resource_type')
    ->distinct()
    ->pluck('resource_type');
@endphp

@if($resource_types->isEmpty())
    <div class="row">
        <div class="col-md-12">
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i> 
                No REST API metrics collected yet. Metrics will appear after the next polling cycle.
            </div>
        </div>
    </div>
@else
    @foreach($resource_types as $resource_type)
        @php
        $resources = DB::table('device_api_metrics')
            ->where('device_id', $device['device_id'])
            ->where('resource_type', $resource_type)
            ->select('resource_name', 'resource_id')
            ->distinct()
            ->get();
        
        if ($resources->isEmpty()) {
            continue;
        }
        
        // Get latest metrics for each resource
        $resource_data = [];
        foreach ($resources as $resource) {
            $metrics = DB::table('device_api_metrics')
                ->where('device_id', $device['device_id'])
                ->where('resource_type', $resource_type)
                ->where('resource_id', $resource->resource_id)
                ->orderBy('collected_at', 'desc')
                ->get();
            
            $resource_data[] = [
                'name' => $resource->resource_name,
                'id' => $resource->resource_id,
                'metrics' => $metrics->groupBy('metric_name')
            ];
        }
        
        // Get all unique metric names for table headers
        $all_metrics = collect();
        foreach ($resource_data as $res) {
            $all_metrics = $all_metrics->merge($res['metrics']->keys());
        }
        $metric_names = $all_metrics->unique()->sort()->take(6);
        
        // Format resource type for display
        $display_type = ucwords(str_replace(['-', '_'], ' ', $resource_type));
        @endphp
        
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-default panel-condensed">
                    <div class="panel-heading">
                        <i class="fa fa-cube fa-lg icon-theme"></i> 
                        <strong>{{ $display_type }} Metrics</strong>
                        <span class="badge pull-right">{{ count($resource_data) }}</span>
                    </div>
                    <table class="table table-hover table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>Resource Name</th>
                                @foreach($metric_names as $metric_name)
                                    <th>{{ ucwords(str_replace(['_', '.'], ' ', $metric_name)) }}</th>
                                @endforeach
                                <th>Last Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($resource_data as $resource)
                            <tr>
                                <td>{{ $resource['name'] }}</td>
                                @foreach($metric_names as $metric_name)
                                    <td>
                                        @if(isset($resource['metrics'][$metric_name]))
                                            @php
                                            $metric = $resource['metrics'][$metric_name]->first();
                                            @endphp
                                            @if($metric->value !== null)
                                                @if($metric->value > 1024 && (strpos($metric_name, 'size') !== false || strpos($metric_name, 'capacity') !== false || strpos($metric_name, 'space') !== false))
                                                    {{ Number::formatBi($metric->value) }}
                                                @else
                                                    {{ number_format($metric->value, 2) }}
                                                @endif
                                            @elseif($metric->string_value !== null)
                                                {{ Str::limit($metric->string_value, 30) }}
                                            @else
                                                -
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-muted small">
                                    @php
                                    $latest = collect($resource['metrics'])->flatten(1)->sortByDesc('collected_at')->first();
                                    @endphp
                                    @if($latest)
                                        {{ \Carbon\Carbon::parse($latest->collected_at)->diffForHumans() }}
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    
                    @if($metric_names->count() < $all_metrics->unique()->count())
                    <div class="panel-footer text-muted small">
                        <i class="fa fa-info-circle"></i> 
                        Showing {{ $metric_names->count() }} of {{ $all_metrics->unique()->count() }} metrics. 
                        Visit the device's REST API tab for full details.
                    </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
@endif

<style>
.panel-condensed .panel-heading { padding: 10px 15px; }
.panel-condensed .table { margin-bottom: 0; }
.badge { background-color: #5bc0de; }
.panel-condensed .panel-footer { padding: 8px 15px; background-color: #f5f5f5; }
</style>
