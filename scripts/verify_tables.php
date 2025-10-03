#!/usr/bin/env php
<?php

/**
 * Quick verification script for REST API tables
 * Run: php scripts/verify_tables.php
 */

$init_modules = [];
require __DIR__ . '/../includes/init.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== REST API Tables Verification ===\n\n";

// Check tables
echo "1. Checking tables...\n";
$tables = [
    'rest_api_connections',
    'rest_api_endpoints',
    'rest_api_credentials',
    'rest_api_metrics',
    'device_api_metrics'
];

foreach ($tables as $table) {
    $exists = Schema::hasTable($table);
    $status = $exists ? '✓ EXISTS' : '✗ MISSING';
    echo "   {$status}: {$table}\n";
    
    if ($exists) {
        $count = DB::table($table)->count();
        echo "            Rows: {$count}\n";
    }
}

echo "\n2. Checking device_api_metrics data...\n";
if (Schema::hasTable('device_api_metrics')) {
    $total = DB::table('device_api_metrics')->count();
    echo "   Total metrics: {$total}\n";
    
    if ($total > 0) {
        $perDevice = DB::table('device_api_metrics')
            ->select('device_id', DB::raw('count(*) as count'))
            ->groupBy('device_id')
            ->get();
        
        echo "   Per device:\n";
        foreach ($perDevice as $row) {
            echo "     - Device {$row->device_id}: {$row->count} metrics\n";
        }
        
        echo "\n   Recent metrics:\n";
        $recent = DB::table('device_api_metrics')
            ->orderBy('collected_at', 'desc')
            ->limit(5)
            ->get(['resource_type', 'resource_name', 'metric_name', 'value', 'string_value', 'collected_at']);
        
        foreach ($recent as $metric) {
            $val = $metric->value ?? substr($metric->string_value ?? '', 0, 30);
            echo "     - [{$metric->resource_type}] {$metric->resource_name} -> {$metric->metric_name} = {$val}\n";
        }
    }
} else {
    echo "   ✗ Table doesn't exist - run migrations!\n";
}

echo "\n3. Checking endpoints configuration...\n";
$endpoints = DB::table('rest_api_endpoints')
    ->select('id', 'name', 'resource_type', 'resource_id_path', 'resource_name_path')
    ->get();

if ($endpoints->isEmpty()) {
    echo "   ✗ No endpoints configured\n";
} else {
    foreach ($endpoints as $endpoint) {
        $hasConfig = $endpoint->resource_type && $endpoint->resource_id_path;
        $status = $hasConfig ? '✓' : '✗';
        echo "   {$status} [{$endpoint->id}] {$endpoint->name}\n";
        if (!$hasConfig) {
            echo "       Missing: resource_type or resource_id_path\n";
        }
    }
}

echo "\n=== Summary ===\n";
if (!Schema::hasTable('device_api_metrics')) {
    echo "❌ Run migrations: php artisan migrate\n";
} else {
    $total = DB::table('device_api_metrics')->count();
    if ($total == 0) {
        echo "⚠️  No metrics stored yet\n";
        echo "→ Run: php scripts/update_purestorage_endpoints.php\n";
        echo "→ Then: php lnms device:poll 1 -m rest-api -vv\n";
    } else {
        echo "✅ Everything is working! {$total} metrics stored.\n";
    }
}

echo "\n";
