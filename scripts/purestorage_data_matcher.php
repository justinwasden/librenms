<?php

/**
 * Proof-of-concept script to match Pure Storage API data to LibreNMS database fields.
 *
 * This script simulates fetching data from the Pure Storage API, attempts to map the
 * data to known LibreNMS database fields, and logs any unmatched data to a CSV file.
 */

echo "Starting Pure Storage Data Matcher PoC...\n";

// --- Configuration ---

// 1. Define the mapping between Pure Storage API fields and LibreNMS DB columns.
// This is a simplified mapping for the PoC. A real implementation would be more complex.
$apiToDbMapping = [
    // Array Space Metrics -> storage table
    'capacity' => ['table' => 'storage', 'column' => 'storage_size'],
    'total_physical' => ['table' => 'storage', 'column' => 'storage_used'],
    'data_reduction' => ['table' => 'storage', 'column' => 'storage_data_reduction'], // Example, column might not exist

    // Hardware Metrics -> sensors table
    'temperature' => ['table' => 'sensors', 'column' => 'sensor_current'],
    'voltage' => ['table' => 'sensors', 'column' => 'sensor_current'], // Another sensor entry

    // Performance Metrics -> device_perf table
    'reads_per_sec' => ['table' => 'device_perf', 'column' => 'read_iops'],
    'writes_per_sec' => ['table' => 'device_perf', 'column' => 'write_iops'],
    'read_bytes_per_sec' => ['table' => 'device_perf', 'column' => 'read_bps'],
    'write_bytes_per_sec' => ['table' => 'device_perf', 'column' => 'write_bps'],
    'usec_per_read_op' => ['table' => 'device_perf', 'column' => 'read_latency_ms'], // Requires conversion
    'usec_per_write_op' => ['table' => 'device_perf', 'column' => 'write_latency_ms'], // Requires conversion
];

// 2. Simulate API data
// In a real scenario, this would come from an API call to the Pure Storage array.
$simulatedApiData = [
    'arrays' => [
        'name' => 'FlashArray-1',
        'capacity' => 10995116277760,
        'total_physical' => 5497558138880,
        'data_reduction' => 5.2,
        'parity' => 0.99, // Unmatched
        'space_saving_ratio' => 10.4, // Unmatched
    ],
    'hardware' => [
        [
            'name' => 'CH0.TEMP0',
            'type' => 'temperature',
            'temperature' => 25,
            'status' => 'healthy',
        ],
        [
            'name' => 'CH0.FAN0',
            'type' => 'fan',
            'speed' => 3000, // Unmatched
            'status' => 'healthy',
        ],
        [
            'name' => 'CT0.VOLT0',
            'type' => 'voltage',
            'voltage' => 206,
            'status' => 'healthy',
        ],
    ],
    'arrays/performance' => [
        'reads_per_sec' => 1500.5,
        'writes_per_sec' => 750.2,
        'read_bytes_per_sec' => 125829120, // 120 MiB/s
        'write_bytes_per_sec' => 62914560,  // 60 MiB/s
        'usec_per_read_op' => 500, // 0.5 ms
        'usec_per_write_op' => 800, // 0.8 ms
        'queue_depth' => 5, // Unmatched
    ],
];

// 3. Setup the unmatched data log file
$unmatchedLogFile = 'unmatched_data.csv';
$logHandle = fopen($unmatchedLogFile, 'w');
if (!$logHandle) {
    die("Error: Could not open log file for writing: $unmatchedLogFile\n");
}
// Write CSV Header
fputcsv($logHandle, ['endpoint', 'field_name', 'value']);

echo "Configuration loaded and log file opened.\n";

// --- Processing ---

function processApiEndpoint($endpointName, $data, $mapping, $logHandle) {
    echo "Processing endpoint: $endpointName\n";
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            // Recursively process nested data structures (like the 'hardware' array)
            processApiEndpoint($endpointName . '/' . $key, $value, $mapping, $logHandle);
            continue;
        }

        if (isset($mapping[$key])) {
            $target = $mapping[$key];
            echo "  [MATCH]   API field '{$key}' => DB '{$target['table']}.{$target['column']}' with value '{$value}'\n";
            // Here you would implement the logic to convert and save the data.
            // For example, converting microseconds to milliseconds:
            if (strpos($key, 'usec_') === 0) {
                $value /= 1000; // convert to ms
                echo "      - Converted value to {$value} ms\n";
            }
        } else {
            echo "  [NO MATCH] API field '{$key}' could not be matched.\n";
            fputcsv($logHandle, [$endpointName, $key, $value]);
        }
    }
}

foreach ($simulatedApiData as $endpoint => $data) {
    processApiEndpoint($endpoint, $data, $apiToDbMapping, $logHandle);
}

// --- Cleanup ---
fclose($logHandle);
echo "Processing complete. Unmatched data logged to: $unmatchedLogFile\n";

?>