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
        echo "Response is an array with " . count($data) . " elements\n\n";

        // Iterate through each config stack
        foreach ($data as $idx => $stack) {
            echo "=== Stack $idx ===\n";
            if (is_array($stack)) {
                echo "Keys: " . implode(", ", array_slice(array_keys($stack), 0, 30)) . "\n";

                // Check for modules
                if (isset($stack["modules"])) {
                    echo "Modules count: " . count($stack["modules"]) . "\n";

                    // Look for deviceSettings module (contains interface configs)
                    foreach ($stack["modules"] as $module) {
                        $moduleName = $module["name"] ?? "unknown";

                        if ($moduleName === "deviceSettings") {
                            echo "\n*** Found deviceSettings module ***\n";
                            if (isset($module["data"])) {
                                echo "Data keys: " . implode(", ", array_keys($module["data"])) . "\n";

                                // Check for LAN interface data
                                if (isset($module["data"]["lan"])) {
                                    $lan = $module["data"]["lan"];
                                    echo "\nLAN configuration:\n";
                                    if (isset($lan["networks"])) {
                                        echo "  Networks count: " . count($lan["networks"]) . "\n";
                                        foreach ($lan["networks"] as $netIdx => $network) {
                                            $ifName = $network["name"] ?? "unknown";
                                            $vlan = $network["vlanId"] ?? 0;
                                            $cidr = $network["cidrPrefix"] ?? "N/A";
                                            echo "    [$netIdx] $ifName (VLAN $vlan) - $cidr\n";
                                        }
                                    }
                                }

                                // Check for routed interfaces
                                if (isset($module["data"]["routedInterfaces"])) {
                                    echo "\nRouted interfaces count: " . count($module["data"]["routedInterfaces"]) . "\n";
                                    foreach ($module["data"]["routedInterfaces"] as $idx => $intf) {
                                        $name = $intf["name"] ?? "unknown";
                                        $wanOverlay = $intf["wanOverlay"] ?? "N/A";
                                        $addressing = $intf["addressing"] ?? [];
                                        $cidr = $addressing["cidrPrefix"] ?? "N/A";
                                        echo "    [$idx] $name - $cidr (WAN Overlay: $wanOverlay)\n";
                                    }
                                }

                                // Check for switched interfaces
                                if (isset($module["data"]["switchedInterfaces"])) {
                                    echo "\nSwitched interfaces count: " . count($module["data"]["switchedInterfaces"]) . "\n";
                                    foreach ($module["data"]["switchedInterfaces"] as $idx => $intf) {
                                        $name = $intf["name"] ?? "unknown";
                                        $vlan = $intf["vlan"] ?? 0;
                                        echo "    [$idx] $name (VLAN $vlan)\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
            echo "\n";
        }
    }
}
