@php
/**
 * REST API Metrics Overview (Blade Router)
 * 
 * Displays REST API metrics for devices with REST API connections enabled
 * Routes to vendor-specific Blade templates or generic fallback
 */

use Illuminate\Support\Facades\DB;

// Check if device has REST API enabled
$api_connection = DB::table('rest_api_connections')
    ->where('device_id', $device['device_id'])
    ->where('enabled', 1)
    ->first();

if (!$api_connection) {
    // No REST API connection, skip this overview
    return;
}

// Determine vendor/os to load appropriate template
$vendor_os = strtolower($device['os']);

// Map alternate OS names to primary vendor names
$os_map = [
    'iosxe' => 'ios',
    'nxos' => 'ios',
    'fortigate' => 'fortios',
    'arista' => 'eos',
];

$vendor_os = $os_map[$vendor_os] ?? $vendor_os;

// Check if vendor-specific Blade template exists
$vendor_blade = "device.overview.rest-api.{$vendor_os}";

if (view()->exists($vendor_blade)) {
    // Render vendor-specific Blade template
    echo view($vendor_blade, ['device' => $device])->render();
} else {
    // Fallback to generic REST API overview
    echo view('device.overview.rest-api.generic', ['device' => $device])->render();
}
@endphp
