<?php
/**
 * Generic REST API Metrics Overview
 * 
 * Displays REST API metrics for any device with REST API enabled
 */

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get all resource types for this device
$resource_types = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->select('resource_type')
    ->distinct()
    ->pluck('resource_type');

if ($resource_types->isEmpty()) {
    echo '<div class="row">
        <div class="col-md-12">
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i> 
                No REST API metrics collected yet. Metrics will appear after the next polling cycle.
            </div>
        </div>
    </div>';
    return;
}

// Display metrics grouped by resource type
foreach ($resource_types as $resource_type) {
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
    
    // Format resource type for display
    $display_type = ucwords(str_replace(['-', '_'], ' ', $resource_type));
    ?>
    
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">
                    <i class="fa fa-cube fa-lg icon-theme" aria-hidden="true"></i> 
                    <strong><?php echo htmlspecialchars($display_type); ?> Metrics</strong>
                    <span class="badge pull-right"><?php echo count($resource_data); ?></span>
                </div>
                <table class="table table-hover table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>Resource Name</th>
                            <?php
                            // Get all unique metric names for table headers
                            $all_metrics = collect();
                            foreach ($resource_data as $res) {
                                $all_metrics = $all_metrics->merge($res['metrics']->keys());
                            }
                            $metric_names = $all_metrics->unique()->sort()->take(6); // Limit columns
                            
                            foreach ($metric_names as $metric_name):
                            ?>
                            <th><?php echo ucwords(str_replace(['_', '.'], ' ', $metric_name)); ?></th>
                            <?php endforeach; ?>
                            <th>Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resource_data as $resource): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($resource['name']); ?></td>
                            <?php foreach ($metric_names as $metric_name): ?>
                            <td>
                                <?php 
                                if (isset($resource['metrics'][$metric_name])) {
                                    $metric = $resource['metrics'][$metric_name]->first();
                                    
                                    // Display value based on type
                                    if ($metric->value !== null) {
                                        // Check if it's a large number (bytes)
                                        if ($metric->value > 1024 && 
                                            (strpos($metric_name, 'size') !== false || 
                                             strpos($metric_name, 'capacity') !== false ||
                                             strpos($metric_name, 'space') !== false)) {
                                            echo Number::formatBi($metric->value);
                                        } else {
                                            echo number_format($metric->value, 2);
                                        }
                                    } elseif ($metric->string_value !== null) {
                                        // Truncate long strings
                                        $display_value = $metric->string_value;
                                        if (strlen($display_value) > 30) {
                                            $display_value = substr($display_value, 0, 27) . '...';
                                        }
                                        echo htmlspecialchars($display_value);
                                    } else {
                                        echo '-';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-muted small">
                                <?php 
                                $latest = $resource['metrics']->flatten(1)->sortByDesc('collected_at')->first();
                                if ($latest) {
                                    echo \Carbon\Carbon::parse($latest->collected_at)->diffForHumans();
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($metric_names->count() < $all_metrics->unique()->count()): ?>
                <div class="panel-footer text-muted small">
                    <i class="fa fa-info-circle"></i> 
                    Showing <?php echo $metric_names->count(); ?> of <?php echo $all_metrics->unique()->count(); ?> metrics. 
                    Visit the device's REST API tab for full details.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
}
?>

<style>
/* Custom styling for REST API metrics */
.panel-condensed .panel-heading {
    padding: 10px 15px;
}
.panel-condensed .table {
    margin-bottom: 0;
}
.badge {
    background-color: #5bc0de;
}
.panel-condensed .panel-footer {
    padding: 8px 15px;
    background-color: #f5f5f5;
}
</style>
