<?php

$file = '/Users/justinwasden/Documents/GitHub/librenms/resources/definitions/config_definitions.json';
$content = file_get_contents($file);

$json = json_decode($content, true);

if (json_last_error() === JSON_ERROR_NONE) {
    echo "✅ JSON is VALID\n";
    
    if (isset($json['config']['poller_modules.rest-api'])) {
        echo "✅ poller_modules.rest-api configuration found:\n";
        echo json_encode($json['config']['poller_modules.rest-api'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "❌ poller_modules.rest-api not found in config\n";
    }
} else {
    echo "❌ JSON is INVALID: " . json_last_error_msg() . "\n";
    echo "Error at position: " . json_last_error() . "\n";
    
    // Find the error location
    $lines = explode("\n", $content);
    echo "Total lines: " . count($lines) . "\n";
}
