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

echo "=== Testing getEdgeConfigurationStack ===\n\n";

// Try getEdgeConfigurationStack
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
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $status\n";
echo "Response length: " . strlen($response) . "\n\n";

if ($status == 200) {
    $data = json_decode($response, true);
    if (is_array($data)) {
        // Look for interface/port related keys
        echo "Top-level keys:\n";
        foreach (array_keys($data) as $key) {
            echo "  - $key\n";
        }

        // Check for modules with interface data
        if (isset($data["modules"])) {
            echo "\nConfiguration modules (" . count($data["modules"]) . " total):\n";
            foreach ($data["modules"] as $module) {
                $moduleName = $module["name"] ?? "unknown";
                echo "\n  Module: $moduleName\n";

                // Look for interface/port/network/device/wan/lan related modules
                $keywords = ['interface', 'device', 'wan', 'lan', 'port', 'network', 'ethernet'];
                $isRelevant = false;
                foreach ($keywords as $keyword) {
                    if (stripos($moduleName, $keyword) !== false) {
                        $isRelevant = true;
                        break;
                    }
                }

                if ($isRelevant) {
                    echo "    ** RELEVANT FOR INTERFACES **\n";
                    if (isset($module["data"])) {
                        echo "    Data keys: " . implode(", ", array_slice(array_keys($module["data"]), 0, 20)) . "\n";

                        // Check for specific interface-related fields
                        if (isset($module["data"]["segments"])) {
                            echo "    Has 'segments' field (count: " . count($module["data"]["segments"]) . ")\n";
                        }
                        if (isset($module["data"]["lan"])) {
                            echo "    Has 'lan' field\n";
                        }
                        if (isset($module["data"]["wan"])) {
                            echo "    Has 'wan' field\n";
                        }
                        if (isset($module["data"]["routed"])) {
                            echo "    Has 'routed' field\n";
                        }
                        if (isset($module["data"]["switchedInterfaces"])) {
                            echo "    Has 'switchedInterfaces' field (count: " . count($module["data"]["switchedInterfaces"]) . ")\n";
                        }
                        if (isset($module["data"]["routedInterfaces"])) {
                            echo "    Has 'routedInterfaces' field (count: " . count($module["data"]["routedInterfaces"]) . ")\n";
                        }
                    }
                }
            }
        }
    }
}

echo "\n\n=== Testing getEdge with full details ===\n\n";

// Also try getEdge with all "with" parameters
$ch = curl_init("https://vco124-usca1.velocloud.net/portal/rest/edge/getEdge");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "enterpriseId" => 1288,
    "id" => 23359,
    "with" => ["links", "site", "configuration", "recentLinks", "wan"]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Cookie: " . $cookie]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $status\n";
echo "Response length: " . strlen($response) . "\n";

if ($status == 200) {
    $data = json_decode($response, true);
    if (is_array($data)) {
        echo "Top-level keys: " . implode(", ", array_keys($data)) . "\n";

        if (isset($data["configuration"])) {
            echo "\nConfiguration keys: " . implode(", ", array_slice(array_keys($data["configuration"]), 0, 20)) . "\n";
        }

        if (isset($data["wan"])) {
            echo "\nWAN data structure:\n";
            echo json_encode($data["wan"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    }
}
