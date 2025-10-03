#!/usr/bin/env php
<?php

// LibreNMS uses a different bootstrap structure
chdir(__DIR__);
$init_modules = [];
require __DIR__ . '/includes/init.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

echo "=== REST API Storage Test ===\n\n";

// Test 1: Check table
echo "1. Checking if rest_api_metrics table exists... ";
if (Schema::hasTable('rest_api_metrics')) {
    echo "YES\n";
} else {
    echo "NO - Run: php artisan migrate\n";
    exit(1);
}

// Test 2: Check table structure
echo "\n2. Table structure:\n";
$columns = DB::select('DESCRIBE rest_api_metrics');
foreach ($columns as $col) {
    echo "   {$col->Field} ({$col->Type}) - NULL: {$col->Null}\n";
}

// Test 3: Try inserting test data
echo "\n3. Testing insert... ";
try {
    DB::table('rest_api_metrics')->insert([
        'endpoint_id' => 1,
        'metric_name' => 'test_' . time(),
        'metric_value' => 'test_value_' . time(),
        'collected_at' => Carbon::now()->toDateTimeString(),
        'created_at' => Carbon::now()->toDateTimeString(),
        'updated_at' => Carbon::now()->toDateTimeString(),
    ]);
    echo "SUCCESS\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Test 4: Count metrics
echo "\n4. Current metrics count: ";
$count = DB::table('rest_api_metrics')->count();
echo "{$count}\n";

// Test 5: Show latest metrics
if ($count > 0) {
    echo "\n5. Latest 5 metrics:\n";
    $latest = DB::table('rest_api_metrics')
        ->latest('id')
        ->limit(5)
        ->get();

    foreach ($latest as $metric) {
        echo "   {$metric->metric_name} = {$metric->metric_value}\n";
    }
}

// Test 6: Check endpoints
echo "\n6. Checking endpoints:\n";
$endpoints = DB::table('rest_api_endpoints')
    ->where('connection_id', 1)
    ->get();

foreach ($endpoints as $ep) {
    $metricCount = DB::table('rest_api_metrics')
        ->where('endpoint_id', $ep->id)
        ->count();
    echo "   [{$ep->id}] {$ep->name}: {$metricCount} metrics\n";
}

// Test 7: Check what's in $GLOBALS during a poll simulation
echo "\n7. Simulating metric storage:\n";
$GLOBALS['poll_state']['rest_api']['custom_metrics'] = [];

// Simulate adding a metric
$GLOBALS['poll_state']['rest_api']['custom_metrics'][] = [
    'endpoint_id' => 1,
    'metric_name' => 'simulated_test',
    'metric_value' => 'simulated_value',
    'collected_at' => Carbon::now(),
];

echo "   Metrics in GLOBALS: " . count($GLOBALS['poll_state']['rest_api']['custom_metrics']) . "\n";

// Try the batch insert logic
if (!empty($GLOBALS['poll_state']['rest_api']['custom_metrics'])) {
    $metricsToInsert = $GLOBALS['poll_state']['rest_api']['custom_metrics'];

    $now = Carbon::now();
    $nowString = $now->toDateTimeString();

    $metricsToInsert = array_map(function ($metric) use ($nowString) {
        if ($metric['collected_at'] instanceof Carbon) {
            $metric['collected_at'] = $metric['collected_at']->toDateTimeString();
        }
        $metric['created_at'] = $nowString;
        $metric['updated_at'] = $nowString;
        return $metric;
    }, $metricsToInsert);

    try {
        $columns = ['endpoint_id', 'metric_name', 'metric_value', 'collected_at', 'created_at', 'updated_at'];
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $insert_query = 'INSERT INTO rest_api_metrics (' . implode(', ', $columns) . ') VALUES ';
        $insert_query .= implode(', ', array_fill(0, count($metricsToInsert), $placeholders));

        $values = [];
        foreach ($metricsToInsert as $metric) {
            $values[] = $metric['endpoint_id'];
            $values[] = $metric['metric_name'];
            $values[] = $metric['metric_value'];
            $values[] = $metric['collected_at'];
            $values[] = $metric['created_at'];
            $values[] = $metric['updated_at'];
        }

        DB::insert($insert_query, $values);
        echo "   ✅ Batch insert successful\n";
    } catch (\Exception $e) {
        echo "   ❌ Batch insert failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Test Complete ===\n";
