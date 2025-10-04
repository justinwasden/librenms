#!/usr/bin/env php
<?php
/**
 * REST API Polling Diagnostic Script
 * 
 * This script helps diagnose issues with REST API polling
 * Run: php diagnostic_rest_api_polling.php <device_id>
 */

require __DIR__ . '/includes/init.php';

use Illuminate\Support\Facades\Log;

$device_id = $argv[1] ?? null;

if (!$device_id) {
    echo "Usage: php diagnostic_rest_api_polling.php <device_id>\n";
    echo "Example: php diagnostic_rest_api_polling.php 2\n";
    exit(1);
}

$device = \App\Models\Device::find($device_id);

if (!$device) {
    echo "❌ Device {$device_id} not found!\n";
    exit(1);
}

echo "==========================================================\n";
echo "REST API POLLING DIAGNOSTIC\n";
echo "==========================================================\n\n";

echo "Device Information:\n";
echo "  ID: {$device->device_id}\n";
echo "  Hostname: {$device->hostname}\n";
echo "  IP: " . ($device->ip ?? 'N/A') . "\n";
echo "  OS: {$device->os}\n";
echo "  Status: " . ($device->status ? '✅ UP' : '❌ DOWN') . "\n";
echo "  Disabled: " . ($device->disabled ? '❌ YES' : '✅ NO') . "\n";
echo "  Ignored: " . ($device->ignore ? '❌ YES' : '✅ NO') . "\n";
echo "\n";

// Check REST API connections
echo "REST API Connections:\n";
$connections = $device->restApiConnections()->with('credential.authenticationType', 'credential.params')->get();

if ($connections->isEmpty()) {
    echo "  ❌ NO CONNECTIONS FOUND\n";
    exit(1);
}

foreach ($connections as $connection) {
    echo "  Connection: {$connection->name}\n";
    echo "    Enabled: " . ($connection->enabled ? '✅ YES' : '❌ NO') . "\n";
    echo "    Base URL: {$connection->base_url}\n";
    echo "    SSL Verify: " . ($connection->disable_ssl_verify ? '❌ Disabled' : '✅ Enabled') . "\n";
    echo "    Rate Limit: " . ($connection->rate_limit ?? 'None') . "\n";
    
    if ($connection->credential) {
        echo "    Credential: {$connection->credential->name}\n";
        echo "    Auth Type: {$connection->credential->authenticationType->name}\n";
    } else {
        echo "    Credential: ❌ NONE\n";
    }
    
    echo "    Endpoints: " . $connection->endpoints->count() . "\n";
    
    if ($connection->endpoints->isEmpty()) {
        echo "      ⚠️  NO ENDPOINTS CONFIGURED\n";
    } else {
        foreach ($connection->endpoints as $endpoint) {
            echo "      - {$endpoint->name}\n";
            echo "        Method: {$endpoint->method}\n";
            echo "        Path: {$endpoint->path}\n";
            echo "        Resource Type: " . ($endpoint->resource_type ?? 'N/A') . "\n";
            echo "        Metric Map: " . (empty($endpoint->metric_map) ? '❌ EMPTY' : '✅ ' . count($endpoint->metric_map) . ' metrics') . "\n";
            echo "        Last Polled: " . ($endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : 'Never') . "\n";
            
            if (empty($endpoint->metric_map)) {
                echo "        ⚠️  WARNING: No metric map configured - no data will be collected!\n";
            }
        }
    }
    echo "\n";
}

// Check existing metrics
echo "Existing Metrics in Database:\n";
$metrics = \Illuminate\Support\Facades\DB::table('device_api_metrics')
    ->where('device_id', $device->device_id)
    ->select('resource_type', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'), \Illuminate\Support\Facades\DB::raw('MAX(collected_at) as last_collected'))
    ->groupBy('resource_type')
    ->get();

if ($metrics->isEmpty()) {
    echo "  ❌ NO METRICS FOUND IN DATABASE\n";
    echo "  This means either:\n";
    echo "    1. Polling hasn't run yet\n";
    echo "    2. Endpoints have no metric_map configured\n";
    echo "    3. API requests are failing\n";
    echo "    4. API responses don't match expected format\n";
} else {
    foreach ($metrics as $metric) {
        echo "  {$metric->resource_type}: {$metric->count} metrics\n";
        echo "    Last collected: " . \Carbon\Carbon::parse($metric->last_collected)->diffForHumans() . "\n";
    }
}
echo "\n";

// Test shouldPoll logic
echo "Module shouldPoll Check:\n";
try {
    $os_class = "\\LibreNMS\\OS\\" . ucfirst($device->os);
    if (class_exists($os_class)) {
        $os = new $os_class($device);
    } else {
        $os = new \LibreNMS\OS\Generic($device);
    }
    
    $status = \LibreNMS\Util\Module::pollingStatus('rest-api', $device);
    $module = new \LibreNMS\Modules\RestApi();
    
    echo "  Module Enabled (Global): " . ($status->isEnabled() ? '✅ YES' : '❌ NO') . "\n";
    echo "  Has Enabled Connections: " . ($device->restApiConnections()->where('enabled', 1)->exists() ? '✅ YES' : '❌ NO') . "\n";
    echo "  Device Status: " . ($device->status ? '✅ UP' : '❌ DOWN') . "\n";
    echo "  Device Not Disabled: " . (!$device->disabled ? '✅ YES' : '❌ NO') . "\n";
    echo "  Device Not Ignored: " . (!$device->ignore ? '✅ YES' : '❌ NO') . "\n";
    
    $should_poll = $module->shouldPoll($os, $status);
    echo "  \n";
    echo "  Final Result: " . ($should_poll ? '✅ SHOULD POLL' : '❌ SHOULD NOT POLL') . "\n";
    
    if (!$should_poll) {
        echo "  \n";
        echo "  ⚠️  Module will NOT poll this device!\n";
        echo "  Fix the issues above before polling can proceed.\n";
    }
} catch (\Exception $e) {
    echo "  ❌ ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// Test actual API call
echo "==========================================================\n";
echo "TEST API CONNECTION (First Enabled Endpoint)\n";
echo "==========================================================\n";

$connection = $connections->where('enabled', 1)->first();
if (!$connection) {
    echo "❌ No enabled connections to test\n";
    exit(0);
}

$endpoint = $connection->endpoints->first();
if (!$endpoint) {
    echo "❌ No endpoints configured to test\n";
    exit(0);
}

echo "Testing: {$endpoint->name}\n";
echo "URL: {$connection->base_url}{$endpoint->path}\n\n";

try {
    $poller = new \App\Pollers\Api($device);
    
    // Enable verbose logging temporarily
    Log::getLogger()->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG));
    
    echo "Executing poll...\n";
    echo "---\n";
    $poller->poll();
    echo "---\n";
    echo "✅ Poll completed\n\n";
    
    // Check if metrics were created
    $new_metrics_count = \Illuminate\Support\Facades\DB::table('device_api_metrics')
        ->where('device_id', $device->device_id)
        ->where('api_endpoint_id', $endpoint->id)
        ->count();
    
    if ($new_metrics_count > 0) {
        echo "✅ {$new_metrics_count} metrics found in database\n";
        
        // Show sample
        $sample = \Illuminate\Support\Facades\DB::table('device_api_metrics')
            ->where('device_id', $device->device_id)
            ->where('api_endpoint_id', $endpoint->id)
            ->limit(5)
            ->get(['resource_type', 'resource_name', 'metric_name', 'value', 'string_value']);
        
        echo "\nSample Metrics:\n";
        foreach ($sample as $m) {
            $val = $m->value ?? $m->string_value;
            echo "  {$m->resource_type}/{$m->resource_name}/{$m->metric_name} = {$val}\n";
        }
    } else {
        echo "⚠️  NO metrics were stored in database\n";
        echo "Check the log output above for errors or warnings\n";
    }
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n";
echo "==========================================================\n";
echo "DIAGNOSTIC COMPLETE\n";
echo "==========================================================\n";
