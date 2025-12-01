<?php

require __DIR__ . '/vendor/autoload.php';

use LibreNMS\Modules\Support\RestNormalizers;

// Login to VeloCloud
$ch = curl_init("https://vco124-usca1.velocloud.net/portal/rest/login/enterpriseLogin");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "username" => "librenms_api@uniti.com",
    "password" => '2}WX*yhZh$"t!2L'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_HEADER, 1);
$loginResponse = curl_exec($ch);
curl_close($ch);

preg_match("/Set-Cookie: (velocloud\.session=[^;]+)/", $loginResponse, $matches);
$cookie = $matches[1] ?? null;

// Get edge configuration stack
$ch = curl_init("https://vco124-usca1.velocloud.net/portal/rest/edge/getEdgeConfigurationStack");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "enterpriseId" => 1288,
    "edgeId" => 23359
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Cookie: " . $cookie]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
$response = curl_exec($ch);
curl_close($ch);

$payload = json_decode($response, true);

echo "API Response received\n";
echo "Payload is array: " . (is_array($payload) ? "YES" : "NO") . "\n";
echo "Payload count: " . count($payload) . "\n\n";

// Test normalizer
echo "Testing normalizeVelocloudConfigStackPorts...\n";
$device = null; // Not needed for this normalizer
$ports = RestNormalizers::normalizeVelocloudConfigStackPorts($device, $payload);

echo "Ports returned: " . count($ports) . "\n\n";

if (count($ports) > 0) {
    echo "All ports:\n";
    foreach ($ports as $idx => $port) {
        echo "  Port $idx: " . ($port['ifName'] ?? 'unknown');
        echo " - Speed: " . ($port['ifSpeed'] ?? 0);
        echo " - Status: " . ($port['ifOperStatus'] ?? 'unknown');
        echo "\n";
    }
} else {
    echo "ERROR: No ports returned!\n";
}

echo "\n\nTesting normalizeVelocloudConfigStackIpv4...\n";
$addresses = RestNormalizers::normalizeVelocloudConfigStackIpv4($device, $payload);

echo "IPv4 addresses returned: " . count($addresses) . "\n";
if (count($addresses) > 0) {
    foreach ($addresses as $addr) {
        echo "  " . $addr['ipv4_address'] . "/" . $addr['ipv4_prefixlen'] . " on " . $addr['ifName'] . "\n";
    }
}
