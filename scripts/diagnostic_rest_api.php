#!/usr/bin/env php
<?php

/**
 * Diagnostic script for REST API polling
 * Run: php scripts/diagnostic_rest_api.php <device_id>
 */

if ($argc < 2) {
    echo "Usage: php scripts/diagnostic_rest_api.php <device_id>\n";
    exit(1);
}

$deviceId = $argv[1];

$init_modules = [];
require __DIR__ . '/../includes/init.php';

use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use Illuminate\Support\Facades\DB;

echo "=== REST API Polling Diagnostics ===\n\n";

// 1. Check if device exists
echo "1. Checking device...\n";
$device = Device::find($deviceId);
if (!$device) {
    echo "   ✗ Device {$deviceId} not found!\n";
    exit(1);
}
echo "   ✓ Device found: {$device->hostname} ({$device->ip})\n\n";

// 2. Check for API connections
echo "2. Checking API connections...\n";
$connections = $device->restApiConnections()->with('credential.authenticationType', 'credential.params')->get();
if ($connections->isEmpty()) {
    echo "   ✗ No API connections configured for this device\n";
    exit(1);
}

foreach ($connections as $conn) {
    echo "   ✓ Connection: {$conn->name}\n";
    echo "     - Base URL: {$conn->base_url}\n";
    echo "     - Enabled: " . ($conn->enabled ? 'Yes' : 'No') . "\n";
    echo "     - SSL Verify: " . ($conn->disable_ssl_verify ? 'Disabled' : 'Enabled') . "\n";
    
    if ($conn->credential) {
        echo "     - Auth Type: {$conn->credential->authenticationType->name}\n";
        echo "     - Credential Params:\n";
        foreach ($conn->credential->params as $param) {
            $value = $param->key === 'password' || $param->key === 'token' || $param->key === 'api_token' 
                ? '***hidden***' 
                : $param->value;
            echo "       • {$param->key}: {$value}\n";
        }
    } else {
        echo "     - Auth Type: None\n";
    }
    echo "\n";
}

// 3. Check endpoints
echo "3. Checking API endpoints...\n";
foreach ($connections as $conn) {
    $endpoints = $conn->endpoints;
    echo "   Connection: {$conn->name}\n";
    
    if ($endpoints->isEmpty()) {
        echo "   ✗ No endpoints configured\n\n";
        continue;
    }
    
    foreach ($endpoints as $endpoint) {
        echo "   ✓ Endpoint: {$endpoint->name}\n";
        echo "     - Path: {$endpoint->path}\n";
        echo "     - Method: {$endpoint->method}\n";
        echo "     - Resource Type: " . ($endpoint->resource_type ?? 'not set') . "\n";
        echo "     - Resource ID Path: " . ($endpoint->resource_id_path ?? 'not set') . "\n";
        echo "     - Resource Name Path: " . ($endpoint->resource_name_path ?? 'not set') . "\n";
        echo "     - Last Polled: " . ($endpoint->last_polled ?? 'Never') . "\n";
        
        if ($endpoint->metric_map) {
            echo "     - Metric Mappings (" . count($endpoint->metric_map) . "):\n";
            foreach ($endpoint->metric_map as $metricName => $apiPath) {
                echo "       • {$metricName} => {$apiPath}\n";
            }
        } else {
            echo "     - Metric Mappings: None configured\n";
        }
        echo "\n";
    }
}

// 4. Check database table
echo "4. Checking database table...\n";
$tableExists = DB::getSchemaBuilder()->hasTable('device_api_metrics');
if (!$tableExists) {
    echo "   ✗ Table 'device_api_metrics' does not exist!\n";
    echo "   → Run migration: php artisan migrate\n\n";
} else {
    echo "   ✓ Table 'device_api_metrics' exists\n";
    
    // Check for existing metrics
    $metricCount = DB::table('device_api_metrics')
        ->where('device_id', $deviceId)
        ->count();
    
    echo "   - Current metrics for device: {$metricCount}\n";
    
    if ($metricCount > 0) {
        echo "   - Recent metrics:\n";
        $recentMetrics = DB::table('device_api_metrics')
            ->where('device_id', $deviceId)
            ->orderBy('collected_at', 'desc')
            ->limit(5)
            ->get(['resource_type', 'resource_name', 'metric_name', 'value', 'string_value', 'collected_at']);
        
        foreach ($recentMetrics as $metric) {
            $val = $metric->value ?? $metric->string_value;
            echo "     • [{$metric->resource_type}] {$metric->resource_name} - {$metric->metric_name}: {$val}\n";
        }
    }
    echo "\n";
}

// 5. Test API connection
echo "5. Testing API connectivity...\n";
foreach ($connections as $conn) {
    if (!$conn->enabled) {
        echo "   - Skipping disabled connection: {$conn->name}\n";
        continue;
    }
    
    try {
        $client = new \GuzzleHttp\Client([
            'timeout' => 10,
            'verify' => !$conn->disable_ssl_verify
        ]);
        
        // Try to hit base URL
        $testUrl = rtrim($conn->base_url, '/');
        echo "   - Testing: {$testUrl}\n";
        
        $response = $client->get($testUrl);
        echo "   ✓ Connection successful (HTTP {$response->getStatusCode()})\n";
        
    } catch (\Exception $e) {
        echo "   ✗ Connection failed: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// 6. Summary and recommendations
echo "=== Summary ===\n\n";

$issues = [];
if ($connections->isEmpty()) {
    $issues[] = "No API connections configured";
}

foreach ($connections as $conn) {
    if ($conn->endpoints->isEmpty()) {
        $issues[] = "Connection '{$conn->name}' has no endpoints";
    }
    
    foreach ($conn->endpoints as $endpoint) {
        if (!$endpoint->metric_map || empty($endpoint->metric_map)) {
            $issues[] = "Endpoint '{$endpoint->name}' has no metric mappings";
        }
        if (!$endpoint->resource_type) {
            $issues[] = "Endpoint '{$endpoint->name}' has no resource_type set";
        }
    }
}

if (!$tableExists) {
    $issues[] = "Database table 'device_api_metrics' does not exist";
}

if (empty($issues)) {
    echo "✓ All checks passed! Ready to poll.\n\n";
    echo "Next steps:\n";
    echo "1. Run polling: php lnms device:poll {$deviceId} -m rest-api -vv\n";
    echo "2. Check metrics: mysql -u librenms -p librenms -e \"SELECT COUNT(*) FROM device_api_metrics WHERE device_id={$deviceId};\"\n";
} else {
    echo "✗ Issues found:\n";
    foreach ($issues as $issue) {
        echo "  • {$issue}\n";
    }
    echo "\nPlease fix these issues before polling.\n";
}

echo "\n";
