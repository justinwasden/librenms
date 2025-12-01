<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use LibreNMS\Modules\Support\RestNormalizers;
use App\ApiClients\VMware\VeloCloudClient;
use App\Models\Device;

$device = Device::find(33);

// Get the VeloCloud client
$config = $device->deviceApiConfig;
if (!$config) {
    die("No API config found for device 33\n");
}

$client = new VeloCloudClient($device, $config);

// Test the getEdgeConfigurationStack endpoint
echo "Fetching edge configuration stack...\n";
$enterpriseId = $config->auth_data['enterprise_id'] ?? 1288;
$edgeId = $config->auth_data['edge_id'] ?? 23359;

$response = $client->post('edge/getEdgeConfigurationStack', [
    'enterpriseId' => (int)$enterpriseId,
    'edgeId' => (int)$edgeId,
]);

echo "Response received, size: " . strlen(json_encode($response)) . " bytes\n";

// Test the normalizer
echo "\nTesting normalizeVelocloudConfigStackPorts...\n";
$ports = RestNormalizers::normalizeVelocloudConfigStackPorts($device, $response);

echo "Ports returned: " . count($ports) . "\n\n";

if (count($ports) > 0) {
    echo "First 3 ports:\n";
    foreach (array_slice($ports, 0, 3) as $idx => $port) {
        echo "  Port $idx: " . ($port['ifName'] ?? 'unknown') . "\n";
        echo "    Speed: " . ($port['ifSpeed'] ?? 0) . " bps\n";
        echo "    Admin: " . ($port['ifAdminStatus'] ?? 'unknown') . "\n";
        echo "    Oper: " . ($port['ifOperStatus'] ?? 'unknown') . "\n";
    }
} else {
    echo "ERROR: No ports returned!\n";
    echo "Response structure:\n";
    if (is_array($response)) {
        echo "  Response is array with " . count($response) . " elements\n";
        if (isset($response[0])) {
            echo "  First element keys: " . implode(", ", array_slice(array_keys($response[0]), 0, 10)) . "\n";
            if (isset($response[0]['modules'])) {
                echo "  Modules count: " . count($response[0]['modules']) . "\n";
                foreach ($response[0]['modules'] as $module) {
                    echo "    Module: " . ($module['name'] ?? 'unknown') . "\n";
                }
            }
        }
    }
}

echo "\n\nTesting normalizeVelocloudConfigStackIpv4...\n";
$addresses = RestNormalizers::normalizeVelocloudConfigStackIpv4($device, $response);

echo "IPv4 addresses returned: " . count($addresses) . "\n";
if (count($addresses) > 0) {
    foreach ($addresses as $addr) {
        echo "  " . $addr['ipv4_address'] . "/" . $addr['ipv4_prefixlen'] . " on " . $addr['ifName'] . "\n";
    }
}
