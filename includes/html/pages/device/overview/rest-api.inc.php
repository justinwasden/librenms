<?php
/**
 * REST API Metrics Overview
 * 
 * Displays REST API metrics for devices with REST API connections enabled
 * Supports multiple vendors with vendor-specific layouts
 */

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Check if device has REST API enabled
$api_connection = DB::table('rest_api_connections')
    ->where('device_id', $device['device_id'])
    ->where('enabled', 1)
    ->first();

if (!$api_connection) {
    return; // No REST API connection, skip this overview
}

// Determine vendor/os to load appropriate template
$vendor_os = strtolower($device['os']);

// Try to load vendor-specific overview file
$vendor_file = "overview/rest-api/{$vendor_os}.inc.php";

if (file_exists("includes/html/pages/device/$vendor_file")) {
    require $vendor_file;
} else {
    // Fallback to generic REST API overview
    require 'overview/rest-api/generic.inc.php';
}
