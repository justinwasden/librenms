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

echo "=== Testing potential interface status endpoints ===\n\n";

$endpoints = [
    "edge/getEdge" => [
        "enterpriseId" => 1288,
        "id" => 23359,
        "with" => ["interfaces", "configuration"]
    ],
    "monitoring/getEdgeLinkStatus" => [
        "enterpriseId" => 1288,
        "edgeId" => 23359
    ],
    "edge/getEdgeDeviceInterfaces" => [
        "enterpriseId" => 1288,
        "edgeId" => 23359
    ],
];

foreach ($endpoints as $endpoint => $body) {
    echo "Testing: $endpoint\n";

    $ch = curl_init("https://vco124-usca1.velocloud.net/portal/rest/$endpoint");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Cookie: " . $cookie]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "  Status: $status\n";

    if ($status == 200) {
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            echo "  Error: " . $data['error']['message'] . "\n";
        } else {
            $size = strlen($response);
            echo "  Response size: $size bytes\n";

            if ($size < 5000 && is_array($data)) {
                echo "  Keys: " . implode(", ", array_slice(array_keys($data), 0, 20)) . "\n";
                if (!empty($data)) {
                    echo "  Sample:\n";
                    echo json_encode(array_slice($data, 0, 1), JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
    }
    echo "\n";
}
