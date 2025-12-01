<?php

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

// Get getEdgeConfigurationStack
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

$data = json_decode($response, true);

if (is_array($data)) {
    // Find the edge-specific configuration (first stack)
    $edgeConfig = $data[0];

    foreach ($edgeConfig["modules"] as $module) {
        if ($module["name"] === "deviceSettings") {
            echo "=== All Routed Interfaces ===\n\n";

            $interfaces = $module["data"]["routedInterfaces"] ?? [];
            foreach ($interfaces as $idx => $intf) {
                echo "Interface #$idx:\n";
                echo json_encode($intf, JSON_PRETTY_PRINT) . "\n\n";
            }

            echo "\n=== LAN Networks ===\n\n";
            $lan = $module["data"]["lan"] ?? [];
            if (isset($lan["networks"])) {
                foreach ($lan["networks"] as $idx => $network) {
                    echo "Network #$idx:\n";
                    echo json_encode($network, JSON_PRETTY_PRINT) . "\n\n";
                }
            }

            break;
        }
    }
}
